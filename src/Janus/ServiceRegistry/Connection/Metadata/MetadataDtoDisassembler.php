<?php

namespace Janus\ServiceRegistry\Connection\Metadata;

class MetadataDtoDisassembler
{
    /**
     * @var MetadataDefinitionHelper
     * @Serializer\Exclude
     */
    private $metadataDefinitionHelper;

    /**
     * @param array $items
     * @param MetadataDefinitionHelper $metadataDefinitionHelper
     */
    public function __construct(MetadataDefinitionHelper $metadataDefinitionHelper)
    {
        $this->metadataDefinitionHelper = $metadataDefinitionHelper;
    }

    /**
     * Turns a nested collection into a flat one.
     *
     * @return array
     */
    public function flatten(MetadataDto $metadataDto, $ignoreMissingDefinition = false)
    {
        $flatCollection = array();
        $parentKey = '';
        $this->flattenEntry($flatCollection, $metadataDto->getItems(), $parentKey, $ignoreMissingDefinition);

        return $flatCollection;
    }

    /**
     * Turns a nested entry of a collection into a flat one recursively.
     *
     * @param array $flatCollection
     * @param array $metadata
     * @param string $parentKey
     */
    public function flattenEntry(
        array &$flatCollection,
        array $metadata,
        &$parentKey = '',
        $ignoreMissingDefinition = false
    )
    {
        foreach ($metadata as $key => $value) {
            $newKey = $this->metadataDefinitionHelper->joinKeyParts($parentKey, $key, $ignoreMissingDefinition);

            if (is_array($value)) {
                $this->flattenEntry($flatCollection, $value, $newKey, $ignoreMissingDefinition);
            } else {
                $flatCollection[$newKey] = $value;
            }
        }
    }
}