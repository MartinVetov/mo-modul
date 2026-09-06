<?php
/**
 * Минимален четец на .xlsx и .csv – без Composer и без PhpSpreadsheet.
 * Използва ZipArchive + SimpleXML, които са включени в XAMPP по подразбиране.
 *
 * SimpleXlsx::rows('/път/файл.xlsx')  ->  [ [клетка, клетка, ...], ... ]
 */
final class SimpleXlsx
{
    /* Пространства от имена в OOXML. Някои програми записват елементите с
       представка (<x:sheet>), други без. Затова четенето е независимо от нея. */
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Дете по име, независимо дали XML-ът ползва представка (<x:sheet>)
     * или подразбиращо се пространство от имена (<sheet>).
     */
    /** Атрибут без пространство от имена (елементът може да е взет с представка). */
    private static function attr(SimpleXMLElement $el, string $name): string
    {
        $a = $el->attributes();
        return isset($a[$name]) ? (string)$a[$name] : '';
    }

    private static function kid(?SimpleXMLElement $el, string $name, string $ns = self::NS_MAIN): ?SimpleXMLElement
    {
        if ($el === null) return null;
        $c = $el->children($ns);
        if (isset($c->$name) && count($c->$name)) return $c->$name;
        return isset($el->$name) && count($el->$name) ? $el->$name : null;
    }

    /**
     * Връща редовете на лист по индекс ИЛИ по име.
     * $extHint е нужен при качени файлове: временният файл на PHP няма
     * разширение, затова видът се подава от истинското име на файла.
     */
    public static function rows(string $path, $sheet = 0, ?string $extHint = null): array
    {
        $ext = strtolower($extHint ?? pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') return self::csvRows($path);
        if ($ext === '' && !self::looksLikeZip($path)) return self::csvRows($path);
        self::requireZip();
        return self::xlsxRows($path, $sheet);
    }

    /** Файлът .xlsx е zip архив и започва с „PK“. */
    private static function looksLikeZip(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        $sig = fread($fh, 2);
        fclose($fh);
        return $sig === 'PK';
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
        $x = $rx !== false ? @simplexml_load_string($rx) : false;
        if ($x !== false) {
            foreach ((self::kid($x, 'Relationship', self::NS_PKG) ?? []) as $r) {
                $t = self::attr($r, 'Target');
                if ($t === '') continue;
                $t = $t[0] === '/' ? ltrim($t, '/') : 'xl/' . ltrim($t, './');
                $rels[self::attr($r, 'Id')] = $t;
            }
        }

        $map = [];
        $wb = $zip->getFromName('xl/workbook.xml');
        $x  = $wb !== false ? @simplexml_load_string($wb) : false;
        if ($x !== false) {
            $sheetsEl = self::kid($x, 'sheets');
            $sheets = $sheetsEl !== null ? self::kid($sheetsEl, 'sheet') : null;
            foreach (($sheets ?? []) as $s) {
                $rid = (string)($s->attributes(self::NS_REL)['id'] ?? '');
                if ($rid === '') $rid = self::attr($s, 'id');
                $name = self::attr($s, 'name');
                if ($name === '') $name = 'Лист ' . (count($map) + 1);
                $map[$name] = $rels[$rid] ?? '';
            }
        }

        // ако връзките липсват, ползваме файловете по ред
        if ($map && !array_filter($map)) {
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (str_starts_with($n, 'xl/worksheets/sheet') && str_ends_with($n, '.xml')) $files[] = $n;
            }
            sort($files);
            $i = 0;
            foreach ($map as $name => $_) { $map[$name] = $files[$i] ?? ''; $i++; }
        }

        $zip->close();
        return $map;
    }

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
        $x  = $ss !== false ? @simplexml_load_string($ss) : false;
        if ($x !== false) {
            foreach ((self::kid($x, 'si') ?? []) as $si) $shared[] = self::siText($si);
        }

        $data = $zip->getFromName($target);
        $zip->close();
        if ($data === false) throw new RuntimeException('Листът не може да бъде прочетен.');

        $xml = @simplexml_load_string($data);
        if ($xml === false) throw new RuntimeException('Повреден xlsx файл.');

        $sheetData = self::kid($xml, 'sheetData');
        if ($sheetData === null) throw new RuntimeException('Листът е празен или в неразпознат формат.');

        $rows = [];
        foreach ((self::kid($sheetData, 'row') ?? []) as $row) {
            $cells = [];
            $max = -1;
            foreach ((self::kid($row, 'c') ?? []) as $c) {
                $ref  = self::attr($c, 'r');
                $col  = $ref !== '' ? self::colIndex($ref) : $max + 1;
                $type = self::attr($c, 't');
                $val = '';
                $is  = self::kid($c, 'is');
                $v   = self::kid($c, 'v');

                if ($type === 'inlineStr') {
                    $val = $is !== null ? self::siText($is) : '';
                } elseif ($v !== null && (string)$v !== '') {
                    $raw = (string)$v;
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
        $tt = self::kid($si, 't');
        if ($tt !== null) $t .= (string)$tt;
        foreach ((self::kid($si, 'r') ?? []) as $r) {
            $rt = self::kid($r, 't');
            if ($rt !== null) $t .= (string)$rt;
        }
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
