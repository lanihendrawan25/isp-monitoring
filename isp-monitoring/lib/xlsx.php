<?php

function xlsEsc(string $s): string
{
    $s = str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = str_replace("\n", '&#10;', $s);
    return $s;
}

function xlsCell(string $ref, $val, int $style): string
{
    if ($val === '' || $val === null) {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }
    if (is_int($val) || is_float($val)) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . $val . '</v></c>';
    }
    $v = xlsEsc((string)$val);
    return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . $v . '</t></is></c>';
}

function clientVal($a, string $key): string
{
    $v = $a[$key] ?? '';
    return $v !== '' && $v !== null ? (string)$v : '-';
}

function clientHours($a): float
{
    $end = $a['downtime_end'] ?: nowDT();
    return max(0, (strtotime($end) - strtotime($a['downtime_start'])) / 3600.0);
}

/**
 * Bangun berkas .xlsx laporan gangguan.
 * Setiap tiket menjadi satu blok; client terimbas muncul pada baris di bawah tiketnya.
 * Mengembalikan path file sementara.
 */
function buildGangguanXlsx(array $tickets, array $clients, string $filterLabel): string
{
    $COLS    = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q'];
    $COLSPAN = count($COLS);

    $rows   = [];
    $merges = [];

    $headerLabels = ['No', 'ID Tiket', 'Subjek Gangguan', 'Vendor', 'Status', 'Awal Gangguan', 'Akhir Gangguan', 'Durasi', 'Dibuka Oleh',
                     'Client Terimbas', 'IP / Range', 'Mode', 'Mulai Downtime', 'Selesai Downtime', 'Durasi Downtime', 'Status Client', 'Pulih Oleh'];

    $r = 1;

    // Judul
    $cells = ['<c r="A' . $r . '" s="1"><is><t>LAPORAN GANGGUAN / TIKET</t></is></c>'];
    for ($i = 1; $i < $COLSPAN; $i++) {
        $cells[] = '<c r="' . $COLS[$i] . $r . '" s="1"/>';
    }
    $rows[]   = '<row r="' . $r . '" ht="30" customHeight="1">' . implode('', $cells) . '</row>';
    $merges[] = 'A' . $r . ':' . $COLS[$COLSPAN - 1] . $r;
    $r++;

    // Info filter
    $meta = 'Filter: ' . $filterLabel
          . '  |  Jumlah Tiket: ' . count($tickets)
          . '  |  Dicetak: ' . date('d M Y H:i') . ' WIB';
    $cells = ['<c r="A' . $r . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . xlsEsc($meta) . '</t></is></c>'];
    for ($i = 1; $i < $COLSPAN; $i++) {
        $cells[] = '<c r="' . $COLS[$i] . $r . '" s="2"/>';
    }
    $rows[]   = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
    $merges[] = 'A' . $r . ':' . $COLS[$COLSPAN - 1] . $r;
    $r++;

    // Header tabel
    $cells = [];
    foreach ($headerLabels as $i => $label) {
        $cells[] = xlsCell($COLS[$i] . $r, $label, 3);
    }
    $rows[] = '<row r="' . $r . '" ht="24" customHeight="1">' . implode('', $cells) . '</row>';
    $r++;

    $no = 0;
    foreach ($tickets as $t) {
        $no++;
        $id     = (int)$t['id'];
        $list   = $clients[$id] ?? [];
        $nCli   = count($list);
        $isOpen = $t['status'] === 'open';

        $ticketVals = [
            $no,
            $id,
            $t['subject'] !== '' ? $t['subject'] : '-',
            $t['vendor_name'] ?: '-',
            $isOpen ? 'Terbuka' : 'Ditutup',
            formatDateTime($t['start_dt']),
            formatDateTime($t['end_dt']),
            formatDuration((float)$t['hours']),
            ($t['created_by_name'] ?? '') !== '' ? $t['created_by_name'] : '-',
        ];
        $ticketCols = count($ticketVals);

        if ($nCli === 0) {
            $cells = [];
            for ($i = 0; $i < $ticketCols; $i++) {
                $cells[] = xlsCell($COLS[$i] . $r, $ticketVals[$i], 4);
            }
            $cells[] = xlsCell($COLS[$ticketCols] . $r, 'Tidak ada client terimbas', 5);
            for ($i = $ticketCols + 1; $i < $COLSPAN; $i++) {
                $cells[] = xlsCell($COLS[$i] . $r, '', 5);
            }
            $rows[]   = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
            $merges[] = $COLS[$ticketCols] . $r . ':' . $COLS[$COLSPAN - 1] . $r;
            $r++;
            continue;
        }

        for ($k = 0; $k < $nCli; $k++) {
            $a     = $list[$k];
            $cells = [];
            if ($k === 0) {
                for ($i = 0; $i < $ticketCols; $i++) {
                    $cells[] = xlsCell($COLS[$i] . $r, $ticketVals[$i], 4);
                }
                if ($nCli > 1) {
                    for ($i = 0; $i < $ticketCols; $i++) {
                        $merges[] = $COLS[$i] . $r . ':' . $COLS[$i] . ($r + $nCli - 1);
                    }
                }
            } else {
                for ($i = 0; $i < $ticketCols; $i++) {
                    $cells[] = xlsCell($COLS[$i] . $r, '', 4);
                }
            }
            $cells[] = xlsCell($COLS[$ticketCols] . $r, clientVal($a, 'client_name'), 5);
            $cells[] = xlsCell($COLS[$ticketCols + 1] . $r, clientVal($a, 'ip_address'), 5);
            $cells[] = xlsCell($COLS[$ticketCols + 2] . $r, monitorModeLabel((string)$a['monitor_mode']), 5);
            $cells[] = xlsCell($COLS[$ticketCols + 3] . $r, formatDateTime($a['downtime_start']), 5);
            $cells[] = xlsCell($COLS[$ticketCols + 4] . $r, $a['downtime_end'] ? formatDateTime($a['downtime_end']) : '-', 5);
            $cells[] = xlsCell($COLS[$ticketCols + 5] . $r, formatDuration(clientHours($a)), 5);
            $cells[] = xlsCell($COLS[$ticketCols + 6] . $r, $a['status'] === 'recovered' ? 'Pulih' : 'Terimbas', 5);
            $cells[] = xlsCell($COLS[$ticketCols + 7] . $r, $a['status'] === 'recovered'
                ? (($a['closed_by_name'] ?: '-') . ' · ' . formatDateTime($a['closed_at']))
                : '-', 5);
            $rows[] = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
            $r++;
        }
    }

    $widths = [6, 8, 42, 16, 12, 18, 18, 15, 16, 22, 17, 11, 18, 18, 15, 13, 17];
    $colXml = '';
    foreach ($widths as $i => $w) {
        $colXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    $mergeXml = '';
    if ($merges) {
        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $m) {
            $mergeXml .= '<mergeCell ref="' . $m . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<cols>' . $colXml . '</cols>'
      . '<sheetData>' . implode('', $rows) . '</sheetData>'
      . $mergeXml
      . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<fonts count="4">'
      . '<font><sz val="10"/><name val="Calibri"/></font>'
      . '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
      . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
      . '<font><b/><sz val="10"/><name val="Calibri"/></font>'
      . '</fonts>'
      . '<fills count="5">'
      . '<fill><patternFill patternType="none"/></fill>'
      . '<fill><patternFill patternType="gray125"/></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
      . '</fills>'
      . '<borders count="2">'
      . '<border><left/><right/><top/><bottom/><diagonal/></border>'
      . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>'
      . '</borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="6">'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
      . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
      . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
      . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
      . '</cellXfs>'
      . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      . '<dxfs count="0"/>'
      . '<tableStyles count="0" defaultTableStyle="TableStyleMedium9" defaultPivotStyle="PivotStyleLight16"/>'
      . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets><sheet name="Laporan Gangguan" sheetId="1" r:id="rId1"/></sheets>'
      . '</workbook>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . '</Types>';

    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>';

    $relsWorkbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
      . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'gangguan_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsRoot);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $relsWorkbook);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    return $tmp;
}
