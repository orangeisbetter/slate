<?php
// One class per table, so a row is a typed value rather than a bag of string keys. The
// helpers in lib.php hydrate them straight from PDO with FETCH_CLASS, which matches
// column names to public properties of the same type — the maze of fromRow() copying is
// exactly what PDO already does. Anything the columns cannot express stays a default
// (assignees, can_login) and is filled in by the helper that knows about it.
//
// Validation is a method rather than constructor logic, because FETCH_CLASS constructs
// the object before the columns land in it: there is nothing to validate at construct
// time. Endpoints call validate() when they build or edit a value; rows that come back
// from the database only need validation if a query fetched a column that is not in the
// schema, which the schema check and the UNIQUE constraints already cover.

require_once __DIR__ . "/lib.php";

final class Project {
    public int $id = 0;
    public string $title = '';
    public string $description = '';
    public string $status = '';
    public string $type = '';
    public ?int $creator_id = null;
    public ?int $parent_id = null;
    public ?string $start_date = null;
    public ?string $due_date = null;
    public string $created_at = '';
    public string $updated_at = '';
    /** @var int[] */
    public array $assignees = [];

    public function validate(): void {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('A project needs a title');
        }
        if (!status_exists($this->status)) {
            throw new InvalidArgumentException("Unknown status: {$this->status}");
        }
        if (!isset(TYPES[$this->type])) {
            throw new InvalidArgumentException("Unknown type: {$this->type}");
        }
        foreach (['start_date', 'due_date'] as $field) {
            if ($this->$field !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->$field)) {
                throw new InvalidArgumentException("$field must be YYYY-MM-DD");
            }
        }
    }
}

final class User {
    public int $id = 0;
    public string $name = '';
    public ?string $email = null;
    public ?string $password_hash = null;
    public ?int $active = null;
    public ?string $slack_id = null;
    public bool $can_login = false;
    /** GROUP_CONCAT(r.slug) on the users list, null elsewhere. Use roleSlugs(). */
    public ?string $roles = null;

    public function roleSlugs(): array {
        return array_values(array_filter(explode(',', (string)$this->roles)));
    }

    public function validate(): void {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A user needs a name');
        }
        if ($this->email !== null && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Not an email address: {$this->email}");
        }
    }
}

final class Board {
    public int $id = 0;
    public string $name = '';
    public string $description = '';
    public ?int $created_by = null;
    public string $created_at = '';
    public ?int $project_count = null;

    public function validate(): void {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A board needs a name');
        }
    }
}

final class Comment {
    public int $id = 0;
    public int $project_id = 0;
    public ?int $author_id = null;
    public string $body = '';
    public string $created_at = '';
    public ?string $edited_at = null;

    public function validate(): void {
        if (trim($this->body) === '') {
            throw new InvalidArgumentException('A comment needs a body');
        }
    }

    public static function getAllForProject(int $project_id): array {
        return q('SELECT * FROM comments WHERE project_id=? ORDER BY id', [$project_id])->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Comment::class);
    }
}

final class Invite {
    public string $token = '';
    public string $email = '';
    public ?string $name = null;
    /** Comma-separated role slugs. Use roleSlugs(). */
    public string $roles = '';
    public ?int $created_by = null;
    public string $created_at = '';
    public string $expires_at = '';
    public ?string $accepted_at = null;
    public ?int $accepted_by = null;
    public ?string $created_by_name = null;

    /** @var string[] */
    public function roleSlugs(): array {
        return array_values(array_filter(explode(',', (string)$this->roles)));
    }

    public function validate(): void {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Not an email address: {$this->email}");
        }
    }
}

final class StatusLogEntry {
    public int $id = 0;
    public int $project_id = 0;
    public ?string $from_status = null;
    public string $to_status = '';
    public string $changed_at = '';
    public ?int $changed_by = null;

    public function validate(): void {
        if (!status_exists($this->to_status)) {
            throw new InvalidArgumentException("Unknown status: {$this->to_status}");
        }
    }
}
