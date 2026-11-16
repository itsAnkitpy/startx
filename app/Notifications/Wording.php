<?php

namespace App\Notifications;

/**
 * Putting a value in place of a named token, and nothing else at all.
 *
 * **This is a wall, not a convenience, and the difference is the whole point.** A client
 * edits the words this product sends, so whatever renders them is handed text somebody
 * outside our company typed. Rendering that through a real template engine is a
 * documented way to hand somebody a shell: Grav published a remote-code-execution
 * advisory for exactly this shape — email templates rendered by Twig without a sandbox —
 * and PortSwigger's own research escapes five popular engines including the sandboxes
 * built specifically to render text a user supplied. Both checked 7 September 2026.
 *
 * So this is not an engine with the dangerous parts switched off. It has no dangerous
 * parts to switch off. A token name is matched, a value is put there, and anything that
 * is not a token name is left exactly as the client typed it — `{{ 1 + 1 }}` arrives in
 * somebody's inbox reading `{{ 1 + 1 }}`, which shows the client their own mistake and
 * costs nothing, because a plain-text email renders nothing at all.
 *
 * **Two things it does not do, both by construction rather than by a guard.** A value put
 * in place is never scanned again, so a person whose name is `{{ company.name }}` does not
 * become the company. And the replacement is returned from a callback rather than written
 * as a pattern, so a value containing `$1` or `\0` is text and not a backreference.
 *
 * Module 09 adds the repeating block a settlement table needs and the condition a letter
 * needs, on top of this rather than instead of it.
 */
final class Wording
{
    /**
     * A token name: lowercase words, joined by dots, and nothing else may appear between
     * the braces. Whitespace inside the braces is allowed and ignored, because a client
     * retyping a token will not match our spacing.
     */
    private const ATokenName = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/';

    /**
     * One message's words with the values in them.
     *
     * A well-formed token with no value renders as nothing, which is what makes a message
     * safe to send when a fact about the case is genuinely absent: the sentence closes up
     * rather than reading `{{ step.on_behalf_of }}` at somebody.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public static function filledIn(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::ATokenName,
            fn (array $token): string => (string) ($values[$token[1]] ?? ''),
            $text,
        );
    }
}
