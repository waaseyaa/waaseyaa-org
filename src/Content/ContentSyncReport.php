<?php

declare(strict_types=1);

namespace App\Content;

final class ContentSyncReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $unpublished = 0;
    public int $unchanged = 0;

    public function summary(): string
    {
        return sprintf(
            'created %d, updated %d, unpublished %d, unchanged %d',
            $this->created,
            $this->updated,
            $this->unpublished,
            $this->unchanged,
        );
    }
}
