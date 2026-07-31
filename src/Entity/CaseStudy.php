<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * One production deployment write-up. Git-authored via
 * content/case-studies/*.md.
 */
#[ContentEntityType(id: 'case_study', label: 'Case study', description: 'A production Waaseyaa deployment.', api: true)]
#[ContentEntityKeys(label: 'title', revision: 'revision_id')]
final class CaseStudy extends ContentEntityBase
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

    #[Field(label: 'Organization', required: true, read: FieldReadLevel::Public)]
    public string $org = '';

    #[Field(label: 'Site URL', read: FieldReadLevel::Public)]
    public string $site_url = '';

    #[Field(label: 'Summary', required: true, read: FieldReadLevel::Public)]
    public string $summary = '';

    #[Field(label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(label: 'Published', read: FieldReadLevel::Public, default: true)]
    public bool $status = true;

    #[Field(label: 'Source SHA1', read: FieldReadLevel::Public)]
    public string $source_sha1 = '';
}
