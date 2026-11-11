<?php
/**
 * Read an uploaded spreadsheet into a table of rows (array of string arrays),
 * whatever Excel or another program saved it as:
 *
 *   - .xlsx / .xlsm workbooks (the first worksheet)
 *   - CSV / text in UTF-8 (with or without BOM), UTF-16 ("Unicode Text") or
 *     Windows-1252 ("CSV (Comma delimited)" on Windows)
 *   - separated by commas, semicolons or tabs
 *
 * The format is decided from the content, not the file name. The old binary
 * .xls format (Excel 97-2003) is reported with a clear message instead.
 * No PHP zip extension is needed: .xlsx is unpacked here with zlib.
 */

const SPREADSHEET_MAX_UNPACKED = 20 * 1048576; // guards against zip bombs

/**
 * @return array{rows: array<int, array<int, string>>, error: ?string}
 */
function read_spreadsheet(string $content): array
{
    if (str_starts_with($content, "PK\x03\x04")) {
        return read_xlsx($content);
    }
    if (str_starts_with($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        return ['rows' => [], 'error' => 'This is an old Excel 97-2003 (.xls) file. In Excel use File > Save As and choose "Excel Workbook (.xlsx)" or "CSV UTF-8", then upload that.'];
    }
    return ['rows' => read_delimited_text(spreadsheet_text_to_utf8($content)), 'error' => null];
}

/** Convert text in UTF-8, UTF-16 (LE/BE, with or without BOM) or Windows-1252 to UTF-8 without a BOM. */
function spreadsheet_text_to_utf8(string $content): string
{
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        return substr($content, 3);
    }
    if (str_starts_with($content, "\xFF\xFE")) {
        return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
    }
    if (str_starts_with($content, "\xFE\xFF")) {
        return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
    }
    // UTF-16 without a BOM: ASCII text with a zero byte beside every character.
    $sample = substr($content, 0, 400);
    if (strlen($sample) >= 4 && substr_count($sample, "\0") > strlen($sample) / 4) {
        $odd_zeros = preg_match_all('/[^\0]\0/', $sample);
        $even_zeros = preg_match_all('/\0[^\0]/', $sample);
        return mb_convert_encoding($content, 'UTF-8', $odd_zeros >= $even_zeros ? 'UTF-16LE' : 'UTF-16BE');
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    return $content;
}

/**
 * Split text into rows and cells. The separator (comma, semicolon or tab) is
 * the one used most in the first line; quoted cells may contain it.
 *
 * @return array<int, array<int, string>>
 */
function read_delimited_text(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = array_values(array_filter(explode("\n", $text), fn($l) => trim($l) !== ''));
    if (!$lines) {
        return [];
    }
    $counts = ["\t" => substr_count($lines[0], "\t"), ';' => substr_count($lines[0], ';'), ',' => substr_count($lines[0], ',')];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    if ($counts[$delimiter] === 0) {
        $delimiter = ',';
    }
    return array_map(fn($line) => str_getcsv($line, $delimiter, '"', ''), $lines);
}

// ---- .xlsx ----------------------------------------------------------------------------

/** @return array{rows: array<int, array<int, string>>, error: ?string} */
function read_xlsx(string $content): array
{
    $unreadable = ['rows' => [], 'error' => 'This Excel file could not be read. Open it in Excel and save it again as .xlsx or "CSV UTF-8".'];
    $files = unzip_entries($content);
    if ($files === null) {
        return $unreadable;
    }

    $sheet_path = xlsx_first_sheet_path($files);
    $sheet = $sheet_path !== null ? xlsx_xml($files[$sheet_path] ?? null) : null;
    if ($sheet === null) {
        return $unreadable;
    }

    $shared = [];
    if ($sst = xlsx_xml($files['xl/sharedStrings.xml'] ?? null)) {
        foreach ($sst->si as $si) {
            $shared[] = xlsx_rich_text($si);
        }
    }

    $rows = [];
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $cells = [];
        $next = 0;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = $ref !== '' ? xlsx_column_index($ref) : $next;
            $next = $col + 1;
            $type = (string) $c['t'];
            $value = match ($type) {
                's' => $shared[(int) $c->v] ?? '',
                'inlineStr' => xlsx_rich_text($c->is),
                'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                default => xlsx_number((string) $c->v),
            };
            $cells[$col] = $value;
        }
        if ($cells && implode('', $cells) !== '') {
            $max = max(array_keys($cells));
            $rows[] = array_map(fn($i) => $cells[$i] ?? '', range(0, $max));
        }
    }
    return ['rows' => $rows, 'error' => null];
}

/** Excel stores 7 as "7" but sometimes 7 as "7.0000000000000009"; show whole numbers plainly. */
function xlsx_number(string $v): string
{
    if (is_numeric($v) && !preg_match('/^0\d/', $v)) {
        $f = (float) $v;
        if (abs($f - round($f)) < 1e-9 && abs($f) < 1e15) {
            return (string) (int) round($f);
        }
    }
    return $v;
}

function xlsx_rich_text(?SimpleXMLElement $node): string
{
    if ($node === null) {
        return '';
    }
    if (isset($node->t)) {
        return (string) $node->t;
    }
    $text = '';
    foreach ($node->r as $run) {
        $text .= (string) $run->t;
    }
    return $text;
}

/** "C12" -> 2 (zero-based column). */
function xlsx_column_index(string $ref): int
{
    $letters = strtoupper(preg_replace('/\d+/', '', $ref));
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return max(0, $n - 1);
}

/** The worksheet Excel shows first (workbook order), falling back to sheet1.xml. */
function xlsx_first_sheet_path(array $files): ?string
{
    $book = xlsx_xml($files['xl/workbook.xml'] ?? null);
    $rels = xlsx_xml($files['xl/_rels/workbook.xml.rels'] ?? null);
    if ($book && $rels && isset($book->sheets->sheet[0])) {
        $rid = (string) $book->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');
                $path = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                if (isset($files[$path])) {
                    return $path;
                }
            }
        }
    }
    foreach ($files as $name => $_) {
        if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
            return $name;
        }
    }
    return null;
}

function xlsx_xml(?string $xml): ?SimpleXMLElement
{
    if ($xml === null || $xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
        return null; // no DTDs: nothing in a workbook needs one
    }
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc === false ? null : $doc;
}

/**
 * Minimal zip reader (stored and deflated entries), enough for .xlsx.
 * Returns [path => contents] for the XML parts, or null if the zip is broken.
 *
 * @return array<string, string>|null
 */
function unzip_entries(string $zip): ?array
{
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false || strlen($zip) < $eocd + 22) {
        return null;
    }
    $end = unpack('vdisk/vcd_disk/ventries_here/ventries/Vcd_size/Vcd_offset', substr($zip, $eocd + 4, 16));
    $pos = $end['cd_offset'];
    $files = [];
    $total = 0;
    for ($i = 0; $i < $end['entries']; $i++) {
        if (substr($zip, $pos, 4) !== "PK\x01\x02") {
            return null;
        }
        $h = unpack('vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Vlocal_offset', substr($zip, $pos + 10, 36));
        $name = substr($zip, $pos + 46, $h['name_len']);
        $pos += 46 + $h['name_len'] + $h['extra_len'] + $h['comment_len'];

        if (!preg_match('/\.(xml|rels)$/i', $name)) {
            continue; // images, printer settings and the like
        }
        $total += $h['usize'];
        if ($h['usize'] > SPREADSHEET_MAX_UNPACKED || $total > SPREADSHEET_MAX_UNPACKED) {
            return null;
        }
        $local = $h['local_offset'];
        if (substr($zip, $local, 4) !== "PK\x03\x04") {
            return null;
        }
        $lh = unpack('vname_len/vextra_len', substr($zip, $local + 26, 4));
        $data = substr($zip, $local + 30 + $lh['name_len'] + $lh['extra_len'], $h['csize']);
        if ($h['method'] === 8) {
            $data = @gzinflate($data, SPREADSHEET_MAX_UNPACKED);
        } elseif ($h['method'] !== 0) {
            return null;
        }
        if ($data === false) {
            return null;
        }
        $files[$name] = $data;
    }
    return $files;
}
