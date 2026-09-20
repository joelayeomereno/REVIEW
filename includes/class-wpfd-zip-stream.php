<?php
/**
 * WPFD_Zip_Stream — Streaming ZIP64 writer.
 *
 * Writes a ZIP archive directly to the output stream as each file is read,
 * with no temporary file and constant memory use. This lets the plugin bundle
 * directories of ANY size (file count and total bytes are effectively unbounded
 * — limited only by what the client can download and the server's request
 * timeout), and the download starts streaming immediately instead of after the
 * whole archive is built.
 *
 * Design choices:
 *  - Store-only (no compression): bytes are byte-for-byte exact and CPU cost is
 *    negligible, so throughput is bound by disk/network, not gzip.
 *  - ZIP64: every entry and the central directory use 64-bit fields, so files
 *    > 4 GB, archives > 4 GB, and > 65,535 entries all work correctly.
 *  - CRC-32 is computed in a streaming pass over each file, so integrity is
 *    preserved without ever holding a file in memory.
 *
 * Because store-mode CRC/size must appear BEFORE the data in a non-seekable
 * stream, each local header sets the streaming bit (general-purpose flag 0x08)
 * and a ZIP64 data descriptor is written after the data. All standard unzip
 * tools (Info-ZIP, 7-Zip, Windows Explorer, macOS Archive Utility) read this.
 *
 * @package WPFD
 * @since   6.6.0
 */

defined( 'ABSPATH' ) || exit;

class WPFD_Zip_Stream {

    /** Read buffer size while copying file bodies (8 MB). */
    const CHUNK = 8 * 1024 * 1024;

    /** Bytes written to the output stream so far (becomes each entry's offset). */
    private int $offset = 0;

    /** Central-directory records, concatenated, emitted at close(). */
    private string $central = '';

    /** Number of entries added. */
    private int $count = 0;

    /** Whether close() has run. */
    private bool $closed = false;

    /**
     * Add a file from disk to the archive, streaming its body to output.
     *
     * @param string $abs_path  Absolute path to a readable file.
     * @param string $entry_name Path inside the ZIP (forward slashes).
     * @return bool True on success, false if the file could not be opened.
     */
    public function add_file( string $abs_path, string $entry_name ): bool {
        $fh = @fopen( $abs_path, 'rb' );
        if ( $fh === false ) {
            return false;
        }

        $name   = $this->sanitize_entry_name( $entry_name );
        $name_b = $this->to_cp437_path( $name );
        $mtime  = (int) @filemtime( $abs_path );
        [ $dos_time, $dos_date ] = $this->dos_datetime( $mtime ?: time() );

        $local_offset = $this->offset;

        // --- Local file header (streaming: sizes/CRC come in the data descriptor) ---
        // General-purpose flag 0x0808: bit 3 = data descriptor follows,
        // bit 11 = UTF-8 filename. Compression method 0 = store.
        $extra_local = $this->zip64_extra_local();
        $header  = pack( 'V', 0x04034b50 );           // local file header signature
        $header .= pack( 'v', 45 );                   // version needed (4.5 = ZIP64)
        $header .= pack( 'v', 0x0808 );               // general purpose bit flag
        $header .= pack( 'v', 0 );                    // compression method = store
        $header .= pack( 'v', $dos_time );
        $header .= pack( 'v', $dos_date );
        $header .= pack( 'V', 0 );                     // CRC-32 (in data descriptor)
        $header .= pack( 'V', 0xFFFFFFFF );            // compressed size -> ZIP64
        $header .= pack( 'V', 0xFFFFFFFF );            // uncompressed size -> ZIP64
        $header .= pack( 'v', strlen( $name_b ) );     // file name length
        $header .= pack( 'v', strlen( $extra_local ) );// extra field length
        $header .= $name_b;
        $header .= $extra_local;

        $this->emit( $header );

        // --- File body: streamed in chunks, CRC computed on the fly ---
        $crc_ctx = hash_init( 'crc32b' );
        $size    = 0;
        while ( ! feof( $fh ) ) {
            $buf = fread( $fh, self::CHUNK );
            if ( $buf === false ) {
                break;
            }
            if ( $buf === '' ) {
                continue;
            }
            hash_update( $crc_ctx, $buf );
            $size += strlen( $buf );
            $this->emit( $buf );

            if ( connection_aborted() ) {
                fclose( $fh );
                return false;
            }
        }
        fclose( $fh );

        $crc = hexdec( hash_final( $crc_ctx ) ) & 0xFFFFFFFF;

        // --- Data descriptor (ZIP64: 8-byte sizes) ---
        $desc  = pack( 'V', 0x08074b50 );             // data descriptor signature
        $desc .= pack( 'V', $crc );
        $desc .= $this->pack64( $size );              // compressed size
        $desc .= $this->pack64( $size );              // uncompressed size
        $this->emit( $desc );

        // --- Record for the central directory (written at close) ---
        $this->central .= $this->central_record( $name_b, $crc, $size, $dos_time, $dos_date, $local_offset );
        $this->count++;

        return true;
    }

    /**
     * Add an empty directory entry (optional; most tools infer dirs from paths).
     */
    public function add_empty_dir( string $entry_name ): void {
        $name = rtrim( $this->sanitize_entry_name( $entry_name ), '/' ) . '/';
        $name_b = $this->to_cp437_path( $name );
        [ $dos_time, $dos_date ] = $this->dos_datetime( time() );

        $local_offset = $this->offset;
        $extra_local  = $this->zip64_extra_local();

        $header  = pack( 'V', 0x04034b50 );
        $header .= pack( 'v', 45 );
        $header .= pack( 'v', 0x0808 );
        $header .= pack( 'v', 0 );
        $header .= pack( 'v', $dos_time );
        $header .= pack( 'v', $dos_date );
        $header .= pack( 'V', 0 );
        $header .= pack( 'V', 0xFFFFFFFF );
        $header .= pack( 'V', 0xFFFFFFFF );
        $header .= pack( 'v', strlen( $name_b ) );
        $header .= pack( 'v', strlen( $extra_local ) );
        $header .= $name_b;
        $header .= $extra_local;
        $this->emit( $header );

        $desc  = pack( 'V', 0x08074b50 );
        $desc .= pack( 'V', 0 );          // CRC of empty content
        $desc .= $this->pack64( 0 );
        $desc .= $this->pack64( 0 );
        $this->emit( $desc );

        $this->central .= $this->central_record( $name_b, 0, 0, $dos_time, $dos_date, $local_offset );
        $this->count++;
    }

    /**
     * Finalize the archive: write the central directory and the ZIP64 end records.
     * After this call no more entries may be added.
     */
    public function close(): void {
        if ( $this->closed ) {
            return;
        }
        $this->closed = true;

        $cd_offset = $this->offset;
        $this->emit( $this->central );
        $cd_size = $this->offset - $cd_offset;

        // --- ZIP64 end of central directory record ---
        $z64  = pack( 'V', 0x06064b50 );
        $z64 .= $this->pack64( 44 );                   // size of remaining record
        $z64 .= pack( 'v', 45 );                       // version made by
        $z64 .= pack( 'v', 45 );                       // version needed
        $z64 .= pack( 'V', 0 );                        // this disk
        $z64 .= pack( 'V', 0 );                        // disk with CD start
        $z64 .= $this->pack64( $this->count );         // entries on this disk
        $z64 .= $this->pack64( $this->count );         // total entries
        $z64 .= $this->pack64( $cd_size );
        $z64 .= $this->pack64( $cd_offset );
        $this->emit( $z64 );

        // --- ZIP64 end of central directory locator ---
        $loc  = pack( 'V', 0x07064b50 );
        $loc .= pack( 'V', 0 );                         // disk with ZIP64 EOCD
        $loc .= $this->pack64( $cd_offset + $cd_size ); // offset of ZIP64 EOCD
        $loc .= pack( 'V', 1 );                         // total disks
        $this->emit( $loc );

        // --- End of central directory record (legacy, with 0xFFFF/0xFFFFFFFF sentinels) ---
        $eocd  = pack( 'V', 0x06054b50 );
        $eocd .= pack( 'v', 0 );                        // this disk
        $eocd .= pack( 'v', 0 );                        // disk with CD start
        $entries16 = $this->count > 0xFFFF ? 0xFFFF : $this->count;
        $eocd .= pack( 'v', $entries16 );
        $eocd .= pack( 'v', $entries16 );
        $eocd .= pack( 'V', $cd_size > 0xFFFFFFFF ? 0xFFFFFFFF : $cd_size );
        $eocd .= pack( 'V', $cd_offset > 0xFFFFFFFF ? 0xFFFFFFFF : $cd_offset );
        $eocd .= pack( 'v', 0 );                        // comment length
        $this->emit( $eocd );
    }

    /** Number of entries written so far. */
    public function entry_count(): int {
        return $this->count;
    }

    /* ----------------------------------------------------------------- *
     *  Internals
     * ----------------------------------------------------------------- */

    /** Write to output, advance the byte counter, and flush. */
    private function emit( string $bytes ): void {
        echo $bytes;
        $this->offset += strlen( $bytes );
        flush();
    }

    /** Build one central-directory file header. */
    private function central_record( string $name_b, int $crc, int $size, int $dos_time, int $dos_date, int $local_offset ): string {
        // ZIP64 extra field carries the real 64-bit sizes and offset.
        $z64  = pack( 'v', 0x0001 );                   // ZIP64 extra tag
        $z64 .= pack( 'v', 24 );                        // data size: 3 * 8 bytes
        $z64 .= $this->pack64( $size );                 // uncompressed
        $z64 .= $this->pack64( $size );                 // compressed
        $z64 .= $this->pack64( $local_offset );         // local header offset

        $rec  = pack( 'V', 0x02014b50 );               // central file header signature
        $rec .= pack( 'v', 45 );                        // version made by
        $rec .= pack( 'v', 45 );                        // version needed
        $rec .= pack( 'v', 0x0808 );                    // flags (data descriptor + UTF-8)
        $rec .= pack( 'v', 0 );                          // method = store
        $rec .= pack( 'v', $dos_time );
        $rec .= pack( 'v', $dos_date );
        $rec .= pack( 'V', $crc );
        $rec .= pack( 'V', 0xFFFFFFFF );                // compressed size -> ZIP64
        $rec .= pack( 'V', 0xFFFFFFFF );                // uncompressed size -> ZIP64
        $rec .= pack( 'v', strlen( $name_b ) );
        $rec .= pack( 'v', strlen( $z64 ) );            // extra field length
        $rec .= pack( 'v', 0 );                          // comment length
        $rec .= pack( 'v', 0 );                          // disk number start
        $rec .= pack( 'v', 0 );                          // internal attributes
        $rec .= pack( 'V', 0 );                          // external attributes
        $rec .= pack( 'V', 0xFFFFFFFF );                // local header offset -> ZIP64
        $rec .= $name_b;
        $rec .= $z64;

        return $rec;
    }

    /** ZIP64 extra field for a local header (sizes unknown -> zero placeholders). */
    private function zip64_extra_local(): string {
        $extra  = pack( 'v', 0x0001 );                 // ZIP64 tag
        $extra .= pack( 'v', 16 );                       // 2 * 8 bytes
        $extra .= $this->pack64( 0 );                    // uncompressed (in descriptor)
        $extra .= $this->pack64( 0 );                    // compressed (in descriptor)
        return $extra;
    }

    /** Pack a 64-bit little-endian unsigned integer. */
    private function pack64( int $n ): string {
        // PHP ints are 64-bit on supported platforms; split into lo/hi 32-bit words.
        $lo = $n & 0xFFFFFFFF;
        $hi = ( $n >> 32 ) & 0xFFFFFFFF;
        return pack( 'V', $lo ) . pack( 'V', $hi );
    }

    /** Convert a Unix timestamp to a DOS time/date pair. */
    private function dos_datetime( int $ts ): array {
        $y = (int) gmdate( 'Y', $ts );
        if ( $y < 1980 ) {
            return [ 0, 0x21 ]; // 1980-01-01
        }
        $time = ( (int) gmdate( 'H', $ts ) << 11 )
              | ( (int) gmdate( 'i', $ts ) << 5 )
              | ( (int) gmdate( 's', $ts ) >> 1 );
        $date = ( ( $y - 1980 ) << 9 )
              | ( (int) gmdate( 'n', $ts ) << 5 )
              | (int) gmdate( 'j', $ts );
        return [ $time & 0xFFFF, $date & 0xFFFF ];
    }

    /**
     * Normalize an entry path: forward slashes, no leading slash, no traversal.
     */
    private function sanitize_entry_name( string $name ): string {
        $name = str_replace( '\\', '/', $name );
        $name = ltrim( $name, '/' );
        $parts = [];
        foreach ( explode( '/', $name ) as $seg ) {
            if ( $seg === '' || $seg === '.' || $seg === '..' ) {
                continue;
            }
            $parts[] = $seg;
        }
        return implode( '/', $parts );
    }

    /**
     * The filename is stored as UTF-8 bytes (flag bit 11 set). Pass through as-is;
     * this method exists as the single point that emits the raw name bytes.
     */
    private function to_cp437_path( string $name ): string {
        return $name;
    }
}
