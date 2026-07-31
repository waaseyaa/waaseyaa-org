<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One tracked waaseyaa/framework release. Git-authored: synced from
 * content/releases/{version}.md by content:sync; never written at runtime.
 */
#[ContentEntityType(id: 'release', label: 'Release', description: 'A tracked waaseyaa/framework release.', api: true)]
#[ContentEntityKeys(label: 'title', revision: 'vid')]
final class Release extends ContentEntityBase
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

    #[Field(label: 'Version', required: true, read: FieldReadLevel::Public)]
    public string $version = '';

    #[Field(label: 'Released at', required: true, read: FieldReadLevel::Public)]
    public string $released_at = '';

    #[Field(label: 'Summary', required: true, read: FieldReadLevel::Public)]
    public string $summary = '';

    #[Field(label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(label: 'Breaking changes', read: FieldReadLevel::Public)]
    public bool $breaking = false;

    #[Field(label: 'Tag URL', read: FieldReadLevel::Public)]
    public string $tag_url = '';

    #[Field(label: 'Published', read: FieldReadLevel::Public, default: true)]
    public bool $status = true;

    #[Field(label: 'Source SHA1', read: FieldReadLevel::Public)]
    public string $source_sha1 = '';
}
