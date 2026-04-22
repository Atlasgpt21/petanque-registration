<?php
/**
 * Minimal XLSX writer — zero dependencies.
 *
 * Δημιουργεί ένα στοιχειώδες .xlsx (OOXML SpreadsheetML) χωρίς composer ή
 * PhpSpreadsheet. Χρησιμοποιεί inline strings (όχι sharedStrings) για απλότητα.
 * Αρκεί για export δεδομένων σε Excel.
 *
 * Χρήση:
 *   $xlsx = new HpfXlsx();
 *   $xlsx->sheet('Άνδρες', [
 *       ['A/A', 'Σύλλογος', 'Επώνυμο', 'Όνομα'],
 *       [1, 'ΑΣ Πετανκ', 'PAPPAS', 'NIKOLAOS'],
 *   ]);
 *   $xlsx->send('men.xlsx');
 */

final class HpfXlsx
{
    /** @var array<string, array<int, array<int, mixed>>> */
    private array $sheets = [];
    /** @var array<string, array<int, bool>> bold flags per sheet row */
    private array $boldRows = [];

    /**
     * Προσθέτει ένα φύλλο με 2D rows. Το πρώτο row θεωρείται header (bold).
     * @param array<int, array<int, mixed>> $rows
     */
    public function sheet(string $name, array $rows, bool $headerBold = true): void
    {
        $safe = $this->sanitizeSheetName($name);
        // Guard κατά unique names (Excel δεν επιτρέπει duplicates)
        $base = $safe; $i = 2;
        while (isset($this->sheets[$safe])) { $safe = $this->truncate($base, 28) . " ($i)"; $i++; }
        $this->sheets[$safe] = $rows;
        $this->boldRows[$safe] = $headerBold && !empty($rows) ? [0 => true] : [];
    }

    /**
     * Στέλνει στον browser ως download (και τερματίζει με exit).
     */
    public function send(string $filename): void
    {
        $zipData = $this->build();
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($zipData));
        header('Cache-Control: max-age=0');
        echo $zipData;
        exit;
    }

    /** Επιστρέφει το .xlsx ως binary string. */
    public function build(): string
    {
        if (empty($this->sheets)) {
            throw new RuntimeException('HpfXlsx: no sheets added');
        }
        $files = [];

        $files['[Content_Types].xml'] = $this->contentTypesXml();
        $files['_rels/.rels'] = $this->rootRelsXml();
        $files['xl/workbook.xml'] = $this->workbookXml();
        $files['xl/_rels/workbook.xml.rels'] = $this->workbookRelsXml();
        $files['xl/styles.xml'] = $this->stylesXml();

        $idx = 0;
        foreach ($this->sheets as $name => $rows) {
            $idx++;
            $files["xl/worksheets/sheet{$idx}.xml"] = $this->sheetXml($rows, $this->boldRows[$name] ?? []);
        }

        return $this->zipInMemory($files);
    }

    // ---------- XML generation ----------

    private function contentTypesXml(): string
    {
        $overrides = '';
        $idx = 0;
        foreach ($this->sheets as $_name => $_rows) {
            $idx++;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $idx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            $overrides .
            '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheetTags = '';
        $idx = 0;
        foreach ($this->sheets as $name => $_rows) {
            $idx++;
            $sheetTags .= '<sheet name="' . $this->xmlEscape($name) . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' . $sheetTags . '</sheets>' .
            '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        $idx = 0;
        foreach ($this->sheets as $_name => $_rows) {
            $idx++;
            $rels .= '<Relationship Id="rId' . $idx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($idx + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            $rels .
            '</Relationships>';
    }

    private function stylesXml(): string
    {
        // 2 styles: [0] default, [1] bold header
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2">' .
                '<font><sz val="11"/><name val="Calibri"/></font>' .
                '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
            '<borders count="1"><border/></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="2">' .
                '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
                '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
            '</cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
            '</styleSheet>';
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, bool> $boldRows
     */
    private function sheetXml(array $rows, array $boldRows): string
    {
        $body = '';
        foreach ($rows as $rIdx => $cells) {
            $rNum = $rIdx + 1;
            $bold = !empty($boldRows[$rIdx]);
            $body .= '<row r="' . $rNum . '">';
            foreach (array_values($cells) as $cIdx => $v) {
                $coord = $this->colLetter($cIdx + 1) . $rNum;
                $styleAttr = $bold ? ' s="1"' : '';
                if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v))) {
                    $body .= '<c r="' . $coord . '"' . $styleAttr . '><v>' . (int)$v . '</v></c>';
                } elseif (is_float($v) || (is_string($v) && preg_match('/^-?\d+\.\d+$/', $v))) {
                    $body .= '<c r="' . $coord . '"' . $styleAttr . '><v>' . (float)$v . '</v></c>';
                } else {
                    $text = $this->xmlEscape((string)$v);
                    $body .= '<c r="' . $coord . '" t="inlineStr"' . $styleAttr . '><is><t xml:space="preserve">' . $text . '</t></is></c>';
                }
            }
            $body .= '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheetData>' . $body . '</sheetData>' .
            '</worksheet>';
    }

    // ---------- ZIP (store method only — no compression) ----------

    /** @param array<string, string> $files name => content */
    private function zipInMemory(array $files): string
    {
        // Prefer ZipArchive (handles Unicode filenames, CRC, offsets correctly)
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'hpfxlsx_');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @unlink($tmp);
                throw new RuntimeException('Cannot open zip for writing');
            }
            foreach ($files as $path => $content) {
                $zip->addFromString($path, $content);
            }
            $zip->close();
            $data = file_get_contents($tmp);
            @unlink($tmp);
            if ($data === false) { throw new RuntimeException('Cannot read built xlsx'); }
            return $data;
        }

        // Fallback: build a minimal ZIP store-only (no compression) in memory.
        $localEntries = '';
        $centralDir   = '';
        $offset = 0;
        $count = 0;
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $len = strlen($content);
            $nameBytes = $name;
            $nameLen = strlen($nameBytes);

            $local = "PK\x03\x04"
                . pack('v', 20)            // version needed
                . pack('v', 0)             // flags
                . pack('v', 0)             // method = store
                . pack('v', 0)             // mod time
                . pack('v', 0x21)          // mod date = 1980-01-01
                . pack('V', $crc)
                . pack('V', $len)          // compressed size
                . pack('V', $len)          // uncompressed size
                . pack('v', $nameLen)
                . pack('v', 0)             // extra len
                . $nameBytes
                . $content;
            $localEntries .= $local;

            $central = "PK\x01\x02"
                . pack('v', 20) . pack('v', 20)
                . pack('v', 0) . pack('v', 0)
                . pack('v', 0) . pack('v', 0x21)
                . pack('V', $crc)
                . pack('V', $len) . pack('V', $len)
                . pack('v', $nameLen) . pack('v', 0) . pack('v', 0)
                . pack('v', 0) . pack('v', 0)
                . pack('V', 0)
                . pack('V', $offset)
                . $nameBytes;
            $centralDir .= $central;

            $offset += strlen($local);
            $count++;
        }
        $cdSize = strlen($centralDir);
        $cdOffset = $offset;
        $eocd = "PK\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', $count) . pack('v', $count)
            . pack('V', $cdSize) . pack('V', $cdOffset)
            . pack('v', 0);
        return $localEntries . $centralDir . $eocd;
    }

    // ---------- helpers ----------

    private function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = (int)(($n - $m - 1) / 26);
        }
        return $s;
    }

    private function xmlEscape(string $s): string
    {
        // Αφαίρεση control chars που δεν επιτρέπονται σε XML 1.0
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeSheetName(string $name): string
    {
        // Excel: max 31 chars, δεν επιτρέπει τους χαρακτήρες: \ / * ? : [ ]
        $s = preg_replace('#[\\\\/*?:\\[\\]]#', '_', $name) ?? $name;
        return $this->truncate($s, 31);
    }

    private function truncate(string $s, int $max): string
    {
        if (function_exists('mb_substr')) { return mb_substr($s, 0, $max); }
        return substr($s, 0, $max);
    }
}
