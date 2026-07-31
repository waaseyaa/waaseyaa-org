<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One roadmap item, grouped by stage-based horizon (now / next / later),
 * never by date. Git-authored via content/roadmap/*.md.
 */
#[ContentEntityType(id: 'roadmap_item', label: 'Roadmap item', description: 'A stage-based roadmap item for the Waaseyaa framework.')]
#[ContentEntityKeys(label: 'title', revision: 'revision_id')]
final class RoadmapItem extends ContentEntityBase
{
    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        // Set defaults before passing to parent.
        $values += [
            'status' => true,
        ];

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    #[Field(label: 'Title', required: true, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(label: 'Slug', required: true, read: FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(label: 'Horizon', required: true, read: FieldReadLevel::Public)]
    public string $horizon = 'later';

    #[Field(label: 'Status note', required: true, read: FieldReadLevel::Public)]
    public string $status_note = '';

    #[Field(label: 'Related specs', read: FieldReadLevel::Public)]
    public string $related_specs = '';

    #[Field(label: 'Weight', read: FieldReadLevel::Public)]
    public int $weight = 0;

    #[Field(label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(label: 'Published', read: FieldReadLevel::Public, default: true)]
    public bool $status = true;

    #[Field(label: 'Source SHA1', read: FieldReadLevel::Public)]
    public string $source_sha1 = '';
}
