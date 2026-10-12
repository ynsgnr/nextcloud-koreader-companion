<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

use OCP\ITempManager;

/** Writes a single-chapter EPUB 3. */
class EpubWriter {

    private const LANGUAGE = 'en';

    public function __construct(
        private ITempManager $tempManager,
    ) {
    }

    /**
     * @param string $chapterXhtml Body content, already valid XHTML
     * @param array<string, EmbeddedImage> $images Images by file name; the chapter refers to them as images/<name>
     */
    public function write(string $title, string $author, string $sourceUrl, string $identifier, string $chapterXhtml, array $images): string {
        $path = $this->tempManager->getTemporaryFile('.epub');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the EPUB archive');
        }

        // Readers sniff the format from a stored `mimetype` entry that comes first.
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', $this->containerDocument());
        $zip->addFromString('OEBPS/content.opf', $this->packageDocument($title, $author, $sourceUrl, $identifier, $images));
        $zip->addFromString('OEBPS/nav.xhtml', $this->navigationDocument($title));
        $zip->addFromString('OEBPS/chapter.xhtml', $this->chapterDocument($title, $chapterXhtml));
        foreach ($images as $fileName => $image) {
            $zip->addFromString('OEBPS/images/' . $fileName, $image->bytes);
        }

        if (!$zip->close()) {
            throw new \RuntimeException('Could not write the EPUB archive');
        }

        return file_get_contents($path) ?: throw new \RuntimeException('Could not read the EPUB back');
    }

    private function containerDocument(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            . '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
            . '</container>';
    }

    /** @param array<string, EmbeddedImage> $images */
    private function packageDocument(string $title, string $author, string $sourceUrl, string $identifier, array $images): string {
        $imageItems = '';
        foreach ($images as $fileName => $image) {
            $imageItems .= sprintf(
                '<item id="%s" href="images/%s" media-type="%s"/>',
                XmlText::escape(pathinfo($fileName, PATHINFO_FILENAME)),
                XmlText::escape($fileName),
                XmlText::escape($image->mimeType)
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="uid">' . XmlText::escape($identifier) . '</dc:identifier>'
            . '<dc:title>' . XmlText::escape($title) . '</dc:title>'
            . ($author !== '' ? '<dc:creator>' . XmlText::escape($author) . '</dc:creator>' : '')
            . '<dc:language>' . self::LANGUAGE . '</dc:language>'
            . ($sourceUrl !== '' ? '<dc:source>' . XmlText::escape($sourceUrl) . '</dc:source>' : '')
            . '<meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . '</meta>'
            . '</metadata>'
            . '<manifest>'
            . '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            . '<item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>'
            . $imageItems
            . '</manifest>'
            . '<spine><itemref idref="chapter"/></spine>'
            . '</package>';
    }

    private function navigationDocument(string $title): string {
        $title = XmlText::escape($title);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">'
            . "<head><title>$title</title></head>"
            . '<body><nav epub:type="toc"><ol><li><a href="chapter.xhtml">' . $title . '</a></li></ol></nav></body>'
            . '</html>';
    }

    private function chapterDocument(string $title, string $chapterXhtml): string {
        $title = XmlText::escape($title);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<html xmlns="http://www.w3.org/1999/xhtml">'
            . "<head><meta charset=\"utf-8\"/><title>$title</title></head>"
            . "<body><h1>$title</h1>$chapterXhtml</body>"
            . '</html>';
    }
}
