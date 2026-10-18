<?php

declare(strict_types=1);

namespace OCA\MetaVox\Dav;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;

/**
 * A property value written as one CDATA section. As plain text every quote of
 * the JSON became &quot;, a third of the listing. XML parsers read both the same.
 */
final class CData implements XmlSerializable {

    public function __construct(
        public readonly string $text,
    ) {
    }

    public function xmlSerialize(Writer $writer): void {
        // ']]>' would end the section; payloads never contain it (JSON_HEX_TAG).
        if (str_contains($this->text, ']]>')) {
            $writer->text($this->text);
            return;
        }
        $writer->writeCData($this->text);
    }
}
