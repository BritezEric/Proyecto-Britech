<?php

namespace App\Core;

/**
 * Generador mínimo de planillas Excel (.xlsx) sin librerías ni extensiones:
 * arma el XML de una hoja y lo empaqueta en un ZIP sin compresión (formato
 * válido para Excel, LibreOffice y Google Sheets). Pensado para exportar
 * listados: un título, líneas de información, encabezado y filas de texto.
 */
class Xlsx
{
    /**
     * @param string   $hoja     nombre de la hoja (máx. 31 caracteres)
     * @param string[] $info     líneas arriba de la tabla (la primera es el título)
     * @param string[] $columnas encabezados
     * @param array[]  $filas    filas de valores (se escriben como texto)
     * @param int[]    $anchos   ancho de cada columna (en caracteres)
     */
    public static function generar(string $hoja, array $info, array $columnas, array $filas, array $anchos = []): string
    {
        $archivos = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::xml(mb_substr($hoja, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            // Estilos: 0 normal · 1 título · 2 encabezado (negrita, fondo gris, borde) · 3 celda con borde y ajuste de texto
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDEDED"/></patternFill></fill></fills>'
                . '<borders count="2"><border/><border><left style="thin"><color rgb="FFD0D0D0"/></left><right style="thin"><color rgb="FFD0D0D0"/></right>'
                . '<top style="thin"><color rgb="FFD0D0D0"/></top><bottom style="thin"><color rgb="FFD0D0D0"/></bottom></border></borders>'
                . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
                . '<cellXfs count="4"><xf/><xf fontId="1" applyFont="1"/>'
                . '<xf fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"/>'
                . '<xf borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
                . '</styleSheet>',
            'xl/worksheets/sheet1.xml' => self::hoja($info, $columnas, $filas, $anchos),
        ];
        return self::zip($archivos);
    }

    private static function hoja(array $info, array $columnas, array $filas, array $anchos): string
    {
        $n = 1;
        $rows = '';
        foreach ($info as $i => $linea) {
            $rows .= '<row r="' . $n . '">' . self::celda(0, $n, $linea, $i === 0 ? 1 : 0) . '</row>';
            $n++;
        }
        $n++;                                   // fila en blanco
        $cabecera = $n;
        $rows .= '<row r="' . $n . '">';
        foreach ($columnas as $c => $t) $rows .= self::celda($c, $n, $t, 2);
        $rows .= '</row>';
        foreach ($filas as $fila) {
            $n++;
            $rows .= '<row r="' . $n . '">';
            foreach (array_values($fila) as $c => $v) $rows .= self::celda($c, $n, $v, 3);
            $rows .= '</row>';
        }
        $cols = '';
        foreach ($anchos as $c => $w) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (int) $w . '" customWidth="1"/>';
        $ultima = self::col(max(0, count($columnas) - 1));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Encabezado fijo al bajar y filtro de Excel sobre la tabla.
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $cabecera . '" topLeftCell="A' . ($cabecera + 1)
            . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols ? "<cols>$cols</cols>" : '')
            . "<sheetData>$rows</sheetData>"
            . '<autoFilter ref="A' . $cabecera . ':' . $ultima . max($cabecera, $n) . '"/>'
            . '</worksheet>';
    }

    private static function celda(int $c, int $fila, $valor, int $estilo): string
    {
        $ref = self::col($c) . $fila;
        if ($valor === null || $valor === '') return '<c r="' . $ref . '" s="' . $estilo . '"/>';
        return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">'
            . self::xml((string) $valor) . '</t></is></c>';
    }

    /** 0 → A, 25 → Z, 26 → AA… */
    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function xml(string $s): string
    {
        // Saca caracteres de control que el XML no admite.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** ZIP "stored" (sin compresión): no necesita la extensión zip de PHP. */
    private static function zip(array $archivos): string
    {
        $datos = '';
        $central = '';
        $hora = (int) ((date('H') << 11) | (date('i') << 5) | intdiv((int) date('s'), 2));
        $fecha = (int) (((date('Y') - 1980) << 9) | (date('n') << 5) | date('j'));
        foreach ($archivos as $nombre => $contenido) {
            $crc = crc32($contenido);
            $largo = strlen($contenido);
            $offset = strlen($datos);
            $datos .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $hora, $fecha, $crc, $largo, $largo, strlen($nombre), 0)
                . $nombre . $contenido;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $hora, $fecha, $crc, $largo, $largo,
                strlen($nombre), 0, 0, 0, 0, 0, $offset) . $nombre;
        }
        return $datos . $central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($archivos), count($archivos), strlen($central), strlen($datos), 0);
    }
}
