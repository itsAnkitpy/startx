<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A refusal made by one of the lists a client company maintains — the designations and
 * the offices.
 *
 * One class covering both lists, and one refusal in it. A duplicate name is refused by
 * the database's own index, which is the right place for it: an index cannot be bypassed
 * by a seeder, an import or a screen somebody writes later.
 */
class ReferenceListRefused extends RuntimeException
{
    /**
     * A row on one of these lists cannot be deleted, only switched off.
     *
     * Job rows point at these rows, so a delete removes a designation from somebody's
     * employment history — and the database refuses it anyway once a single job row names
     * the row. This refusal covers the case the database cannot: a row nothing points at
     * yet, deleted through a seeder or a screen wired up by mistake, where the client
     * loses the row and nobody finds out until the next time they look for it.
     *
     * How far it reaches, measured on 20 August 2026 rather than assumed: it covers a
     * delete of one row, which is what a screen's delete button does and the only way
     * anyone will realistically try. A delete written against the whole table at once
     * (`Designation::query()->delete()`) never fires a model event and walks straight
     * past this — rows a job row names are still refused by the key, rows nothing names
     * yet are not. Do not trust this refusal further than that.
     */
    public static function deletion(Model $row): self
    {
        $name = (string) $row->getAttribute('name');

        return new self(
            "[{$name}] cannot be deleted, only switched off, because employment records point at "
            ."it and a delete would take it out of somebody's history."
        );
    }

    /**
     * A job row names a list entry this client company does not have.
     *
     * Added 20 August 2026 on reviewing the finished code. Without it the failure arrived
     * from the database as "this row has a link with no name frozen beside it", which
     * sends whoever is debugging an import to the write path rather than to the wrong
     * number in their file — and, being a database error, it abandons everything else in
     * the same save, where a refusal raised before the insert does not.
     *
     * The number is in the message rather than the name, because there is no name: the
     * entry either belongs to another client company or does not exist at all, and the
     * two are the same answer from in here.
     */
    public static function unknownEntry(string $list, int $id): self
    {
        return new self(
            "This client company has no {$list} numbered {$id}, so an employment record cannot "
            .'name it. A list entry belongs to one client company and is never shared.'
        );
    }
}
