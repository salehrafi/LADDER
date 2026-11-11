<?php
// Uploaded student lists: every format Excel (or another program) can save.

require_once APP_ROOT . '/includes/class_helpers.php';

/** Build a zip in memory (this PHP may have no zip extension). $deflate compresses entries like Excel does. */
function make_zip(array $files, bool $deflate = true): string
{
    $out = '';
    $central = '';
    foreach ($files as $name => $data) {
        $packed = $deflate ? gzdeflate($data) : $data;
        $method = $deflate ? 8 : 0;
        $crc = crc32($data);
        $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, strlen($packed), strlen($data), strlen($name), 0) . $name . $packed;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0, $crc, strlen($packed), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
}

/** A minimal .xlsx: shared strings, an inline string, numbers and a skipped (empty) cell. */
function make_xlsx(bool $deflate = true): string
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    return make_zip([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'xl/workbook.xml' => "<?xml version=\"1.0\"?><workbook xmlns=\"$ns\" xmlns:r=\"$r\"><sheets><sheet name=\"Students\" sheetId=\"1\" r:id=\"rId7\"/></sheets></workbook>",
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/roster.xml"/></Relationships>',
        'xl/sharedStrings.xml' => "<?xml version=\"1.0\"?><sst xmlns=\"$ns\"><si><t>name</t></si><si><t>email</t></si><si><t>id</t></si><si><t>section</t></si><si><r><t>Aarav </t></r><r><t>Rahman</t></r></si><si><t>Aarav.Rahman@Gmail.com</t></si><si><t>2026-2-60-123</t></si></sst>",
        'xl/worksheets/roster.xml' => "<?xml version=\"1.0\"?><worksheet xmlns=\"$ns\"><sheetData>"
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2" t="s"><v>6</v></c><c r="D2"><v>1</v></c></row>'
            . '<row r="3"><c r="A3" t="inlineStr"><is><t>Nusrat Jahan</t></is></c><c r="B3" t="inlineStr"><is><t>nusrat@x.edu</t></is></c><c r="D3"><v>2.0000000000000004</v></c></row>'
            . '<row r="4"></row>'
            . '</sheetData></worksheet>',
    ], $deflate);
}

test('an Excel workbook (.xlsx) imports, compressed or not', function () {
    foreach ([true, false] as $deflate) {
        $r = parse_student_csv(make_xlsx($deflate));
        assert_same([], $r['errors']);
        assert_same(2, count($r['rows']), 'the empty row is ignored');
        assert_same(['line' => 2, 'name' => 'Aarav Rahman', 'email' => 'aarav.rahman@gmail.com', 'id' => '2026-2-60-123', 'section' => '1'], $r['rows'][0]);
        assert_same('', $r['rows'][1]['id'], 'a skipped cell stays empty and later cells keep their column');
        assert_same('2', $r['rows'][1]['section'], 'whole numbers read plainly');
    }
});

test('Excel "Unicode Text" (UTF-16, tab separated) imports', function () {
    $text = "name\temail\tid\tsection\r\nAarav Rahman\taarav.rahman@gmail.com\t2026-2-60-123\t1\r\n";
    foreach (["\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'),
              "\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'),
              mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')] as $file) {
        $r = parse_student_csv($file);
        assert_same([], $r['errors']);
        assert_same(['line' => 2, 'name' => 'Aarav Rahman', 'email' => 'aarav.rahman@gmail.com', 'id' => '2026-2-60-123', 'section' => '1'], $r['rows'][0]);
    }
});

test('tab-separated and Windows-1252 CSV files import', function () {
    $r = parse_student_csv("Name\tEmail\nRafi Ahmed\trafi@x.edu");
    assert_same('rafi@x.edu', $r['rows'][0]['email']);

    $r = parse_student_csv("name,email\nJos\xE9 M\xFCller,jose@x.edu"); // "CSV (Comma delimited)" on Windows
    assert_same('José Müller', $r['rows'][0]['name']);
});

test('old .xls and damaged workbooks give a clear message', function () {
    $xls = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64);
    assert_contains('Excel 97-2003', parse_student_csv($xls)['errors'][0]);
    assert_contains('could not be read', parse_student_csv(substr(make_xlsx(), 0, 200))['errors'][0]);
    assert_contains('could not be read', parse_student_csv(make_zip(['a.txt' => 'hi']))['errors'][0]);
});
