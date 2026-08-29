<?php
/**
 * Минимален четец на .xlsx и .csv – без Composer и без PhpSpreadsheet.
 * Използва ZipArchive + SimpleXML, които са включени в XAMPP по подразбиране.
 *
 * SimpleXlsx::rows('/път/файл.xlsx')  ->  [ [клетка, клетка, ...], ... ]
 */
final class SimpleXlsx
{
    /** Връща редовете на лист по индекс ИЛИ по име. */
    public static function rows(string $path, $sheet = 0): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') return self::csvRows($path);
        self::requireZip();
        return self::xlsxRows($path, $sheet);
    }

    /**
     * Четенето на .xlsx изисква разширението zip на PHP.
     * В XAMPP то често е изключено – затова обясняваме какво да се направи.
     */
    private static function requireZip(): void
    {
        if (class_exists('ZipArchive')) return;
        throw new RuntimeException(
            'Разширението „zip“ на PHP е изключено, а без него .xlsx файл не може да бъде прочетен. '
          . 'Отворете XAMPP Control Panel → Apache → Config → PHP (php.ini), намерете реда '
          . '„;extension=zip“, махнете точката и запетаята отпред и рестартирайте Apache. '
          . 'Като бърз заобиколен път запишете таблицата от Excel като CSV UTF-8 и качете нея – '
          . 'CSV не изисква това разширение.'
        );
    }

    /** Имената на листовете в xlsx файл (в реда от работната книга). */
    public static function sheetNames(string $path): array
    {
        self::requireZip();
        return array_keys(self::sheetMap($path));
    }

    /**
     * име на лист => път до XML-а вътре в архива.
     * Минава през workbook.xml.rels, защото номерът на файла sheetN.xml
     * невинаги съответства на реда на листовете.
     */
    private static function sheetMap(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];

        $rels = [];
        $rx = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rx !== false && ($x = @simplexml_load_string($rx))) {
            foreach ($x->Relationship as $r) {
                $t = (string)$r['Target'];
                if ($t !== '' && $t[0] !== '/') $t = 'xl/' . ltrim($t, './');
                $rels[(string)$r['Id']] = ltrim($t, '/');
            }
        }

        $map = [];
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb !== false && ($x = @simplexml_load_string($wb))) {
            $ns = $x->getNamespaces(true);
            foreach ($x->sheets->sheet as $s) {
                $rid = '';
                foreach ($ns as $prefix => $uri) {
                    $attr = $s->attributes($uri);
                    if (isset($attr['id'])) { $rid = (string)$attr['id']; break; }
                }
                $map[(string)$s['name']] = $rels[$rid] ?? '';
            }
        }
        $zip->close();
        return $map;
    }

    /* ---------------------------------------------------------------- */

    private static function csvRows(string $path): array
    {
        $out = [];
        if (($fh = fopen($path, 'r')) === false) return $out;
        // разделител: ; или ,
        $first = fgets($fh);
        rewind($fh);
        $delim = (substr_count((string)$first, ';') > substr_count((string)$first, ',')) ? ';' : ',';
        $bomStripped = false;
        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            if (!$bomStripped && isset($r[0])) {
                $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
                $bomStripped = true;
            }
            $out[] = array_map(static fn($v) => trim((string)$v), $r);
        }
        fclose($fh);
        return $out;
    }

    private static function xlsxRows(string $path, $sheet): array
    {
        $map = self::sheetMap($path);
        if (!$map) throw new RuntimeException('Файлът не може да бъде отворен като .xlsx');

        if (is_string($sheet) && isset($map[$sheet])) {
            $target = $map[$sheet];
        } else {
            $paths  = array_values($map);
            $target = $paths[(int)$sheet] ?? ($paths[0] ?? '');
        }
        if ($target === '') throw new RuntimeException('Липсва такъв лист в xlsx файла.');

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Файлът не може да бъде отворен.');

        // споделени низове
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && ($x = @simplexml_load_string($ss))) {
            foreach ($x->si as $si) $shared[] = self::siText($si);
        }

        $data = $zip->getFromName($target);
        $zip->close();
        if ($data === false) throw new RuntimeException('Листът не може да бъде прочетен.');

        $xml = @simplexml_load_string($data);
        if (!$xml) throw new RuntimeException('Повреден xlsx файл.');

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            $max = -1;
            foreach ($row->c as $c) {
                $ref  = (string)$c['r'];
                $col  = self::colIndex($ref);
                $type = (string)$c['t'];
                $val  = '';
                if ($type === 'inlineStr') {
                    $val = isset($c->is) ? self::siText($c->is) : '';
                } elseif (isset($c->v)) {
                    $raw = (string)$c->v;
                    if ($type === 's') {
                        $val = $shared[(int)$raw] ?? '';
                    } elseif ($type === 'b') {
                        $val = $raw === '1' ? 'ДА' : 'НЕ';
                    } else {
                        $val = $raw;
                    }
                }
                $cells[$col] = trim($val);
                if ($col > $max) $max = $col;
            }
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
            $rows[] = $line;
        }
        return $rows;
    }

    private static function siText($si): string
    {
        $t = '';
        if (isset($si->t)) $t .= (string)$si->t;
        if (isset($si->r)) foreach ($si->r as $r) $t .= (string)$r->t;
        return $t;
    }

    /** "BC12" -> 54 (0-базиран индекс на колоната) */
    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/\d/', '', $ref);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord(strtoupper($letters[$i])) - 64);
        }
        return max(0, $n - 1);
    }
}
