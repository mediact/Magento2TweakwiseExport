<?php
/**
 * Tweakwise & Emico (https://www.tweakwise.com/ & https://www.emico.nl/) - All Rights Reserved
 *
 * @copyright Copyright (c) 2017-2017 Tweakwise.com B.V. (https://www.tweakwise.com)
 * @license   Proprietary and confidential, Unauthorized copying of this file, via any medium is strictly prohibited
 */

namespace Emico\TweakwiseExport\Model\Write;

class Categories implements WriterInterface
{
    /**
     * @var EavIterator
     */
    protected $iterator;

    /**
     * Categories constructor.
     *
     * @param EavIterator $iterator
     */
    public function __construct(EavIterator $iterator)
    {
        $this->iterator = $iterator;
    }

    /**
     * {@inheritdoc}
     */
    public function write(Writer $writer, XmlWriter $xml)
    {
        $xml->startAttribute('categories');
        foreach ($this->iterator as $data) {
            var_dump($data);
        }
        $xml->endAttribute();
        $writer->flush();
        return $this;
    }
}