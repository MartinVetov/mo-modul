<?php
/**
 * Създава .docx файл без Composer и без PhpWord – само със ZipArchive.
 *
 * Форматиране по изискването на училището:
 *   – шрифт Times New Roman навсякъде
 *   – основен текст 12pt, двустранно подравнен
 *   – заглавия 16pt, получер, центрирани
 *
 * Употреба:
 *   $d = new DocxWriter('Заглавие');
 *   $d->heading('Раздел');
 *   $d->paragraph('текст');
 *   $d->table(['Колона A','Колона Б'], [['1','2']]);
 *   $d->save('/път/файл.docx');
 */
final class DocxWriter
{
    /** @var string[] XML на параграфите */
    private array $body = [];

    public function __construct(?string $title = null, ?string $subtitle = null)
    {
        if ($title !== null)    $this->heading($title, 32);      // 16pt
        if ($subtitle !== null) $this->paragraph($subtitle, ['align' => 'center', 'italic' => true, 'size' => 22]);
    }

    /** Заглавие: получер, центрирано, по подразбиране 16pt. */
    public function heading(string $text, int $halfPoints = 32): void
    {
        $this->body[] = '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="240" w:after="120"/>'
            . '<w:rPr><w:b/><w:sz w:val="' . $halfPoints . '"/><w:szCs w:val="' . $halfPoints . '"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="' . $halfPoints . '"/><w:szCs w:val="' . $halfPoints . '"/></w:rPr>'
            . $this->runText($text) . '</w:r></w:p>';
    }

    /**
     * Абзац. По подразбиране 12pt, двустранно подравнен.
     * $opt: align (both|center|left|right), bold, italic, size (полупункта)
     */
    public function paragraph(string $text, array $opt = []): void
    {
        $align  = $opt['align'] ?? 'both';
        $size   = (int)($opt['size'] ?? 24);          // 24 полупункта = 12pt
        $bold   = !empty($opt['bold'])   ? '<w:b/>'  : '';
        $italic = !empty($opt['italic']) ? '<w:i/>'  : '';
        $rpr    = '<w:rPr>' . $bold . $italic . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';

        // празен абзац за разстояние
        if (trim($text) === '') {
            $this->body[] = '<w:p><w:pPr><w:jc w:val="' . $align . '"/></w:pPr></w:p>';
            return;
        }

        $runs = '';
        foreach (preg_split('/\R/u', $text) as $i => $line) {
            if ($i > 0) $runs .= '<w:r>' . $rpr . '<w:br/></w:r>';
            $runs .= '<w:r>' . $rpr . $this->runText($line) . '</w:r>';
        }
        $this->body[] = '<w:p><w:pPr><w:jc w:val="' . $align . '"/>'
            . '<w:spacing w:after="120" w:line="276" w:lineRule="auto"/>' . $rpr . '</w:pPr>' . $runs . '</w:p>';
    }

    /** Списък с водещи чертички (Word ги показва като обикновени абзаци с тире). */
    public function bullets(array $items): void
    {
        foreach ($items as $it) {
            $this->body[] = '<w:p><w:pPr><w:jc w:val="both"/><w:ind w:left="360" w:hanging="180"/>'
                . '<w:spacing w:after="60"/></w:pPr>'
                . '<w:r><w:rPr><w:sz w:val="24"/></w:rPr>' . $this->runText('– ' . $it) . '</w:r></w:p>';
        }
    }

    /** Проста таблица с рамки; първият ред е заглавен и получер. */
    public function table(array $head, array $rows): void
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'
             . '<w:tblBorders>'
             . '<w:top w:val="single" w:sz="6" w:color="666666"/>'
             . '<w:left w:val="single" w:sz="6" w:color="666666"/>'
             . '<w:bottom w:val="single" w:sz="6" w:color="666666"/>'
             . '<w:right w:val="single" w:sz="6" w:color="666666"/>'
             . '<w:insideH w:val="single" w:sz="4" w:color="999999"/>'
             . '<w:insideV w:val="single" w:sz="4" w:color="999999"/>'
             . '</w:tblBorders></w:tblPr>';

        $xml .= '<w:tr>';
        foreach ($head as $h) $xml .= $this->cell((string)$h, true);
        $xml .= '</w:tr>';

        foreach ($rows as $r) {
            $xml .= '<w:tr>';
            foreach ($r as $c) $xml .= $this->cell((string)$c, false);
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl><w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
        $this->body[] = $xml;
    }

    /** Ред за подпис в дъното. */
    public function signature(string $left, string $right = ''): void
    {
        $this->paragraph('', []);
        $this->paragraph($left . ($right !== '' ? str_repeat(' ', 8) . $right : ''), ['align' => 'left']);
    }

    /** Записва файла. Връща броя байтове. */
    public function save(string $path): int
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Разширението „zip“ на PHP е изключено – без него не може да се създаде .docx файл. '
                . 'В php.ini махнете „;“ пред extension=zip и рестартирайте Apache.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Папката за документи не може да бъде създадена: ' . $dir);
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Файлът не може да бъде записан: ' . $path);
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('word/document.xml', $this->document());
        $zip->addFromString('word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
          . ' Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->close();

        return (int)filesize($path);
    }

    /* ---------------------------------------------------------------- */

    private function cell(string $text, bool $bold): string
    {
        $rpr = '<w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>';
        return '<w:tc><w:tcPr><w:vAlign w:val="center"/></w:tcPr>'
             . '<w:p><w:pPr><w:jc w:val="' . ($bold ? 'center' : 'left') . '"/>'
             . '<w:spacing w:before="40" w:after="40"/></w:pPr>'
             . '<w:r>' . $rpr . $this->runText($text) . '</w:r></w:p></w:tc>';
    }

    /** Текстът в run – с пазене на интервалите. */
    private function runText(string $s): string
    {
        return '<w:t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>';
    }

    private function document(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
          . '<w:body>' . implode('', $this->body)
          . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'          // A4
          . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1418" w:header="708" w:footer="708"/>'
          . '</w:sectPr></w:body></w:document>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
          . '<w:docDefaults><w:rPrDefault><w:rPr>'
          . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/>'
          . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="bg-BG"/>'
          . '</w:rPr></w:rPrDefault>'
          . '<w:pPrDefault><w:pPr><w:jc w:val="both"/></w:pPr></w:pPrDefault></w:docDefaults>'
          . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
          . '<w:name w:val="Normal"/><w:qFormat/>'
          . '<w:pPr><w:jc w:val="both"/></w:pPr>'
          . '<w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/><w:sz w:val="24"/></w:rPr>'
          . '</w:style></w:styles>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
          . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
          . '</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
          . ' Target="word/document.xml"/></Relationships>';
    }
}
