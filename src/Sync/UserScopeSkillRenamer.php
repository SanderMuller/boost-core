<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillAsset;

/**
 * Gives a package's user-scope skills their own `-user` names.
 *
 * User scope publishes each skill flat, as `~/.{agent}/skills/<name>-user/`.
 * The suffix keeps a user-scope copy from hiding a project skill of the same
 * name (an agent prefers the personal skill, which lacks the project's
 * resolved conventions), and the flat folder is the layout agents discover.
 *
 * References between the published skills are rewritten too, so a published
 * `interview-user` hands off to `write-spec-user`. Only names in the published
 * set change, and only in three forms, in `.md` content:
 *  - a whole backticked name: `` `write-spec` ``;
 *  - a slash command: `/clarify`, `/autoresearch:plan`;
 *  - a relative skill link: `../codex-review/`.
 *
 * The `metadata.boost-requires` tokens that name a published skill get the
 * suffix as well, so the copy declares the dependencies it actually has. A
 * `subagent:` token, a name outside the published set, and a value that is
 * not a string stay as they are. Plain-text names in the description and
 * the body are not rewritten: they are often ordinary words.
 *
 * @internal
 */
final readonly class UserScopeSkillRenamer
{
    public const SUFFIX = '-user';

    /** Agent Skills spec limit for a skill name. */
    public const MAX_NAME_LENGTH = 64;

    /**
     * @param  list<Skill>  $skills
     * @return array{skills: list<Skill>, warnings: list<string>}
     */
    public function rename(array $skills): array
    {
        $warnings = [];
        /** @var list<Skill> $kept */
        $kept = [];
        foreach ($skills as $skill) {
            if (strlen($skill->name . self::SUFFIX) > self::MAX_NAME_LENGTH) {
                $warnings[] = sprintf(
                    'Skipped skill "%s": "%s%s" is longer than the %d-character skill name limit.',
                    $skill->name,
                    $skill->name,
                    self::SUFFIX,
                    self::MAX_NAME_LENGTH,
                );

                continue;
            }

            $kept[] = $skill;
        }

        $names = array_map(static fn (Skill $skill): string => $skill->name, $kept);

        $renamed = [];
        foreach ($kept as $skill) {
            $renamed[] = $this->renameOne($skill, $names);
        }

        return ['skills' => $renamed, 'warnings' => $warnings];
    }

    /**
     * Rewrite references to `$names` in Markdown content (see the class doc).
     *
     * @param  list<string>  $names
     */
    public static function rewriteReferences(string $content, array $names): string
    {
        if ($names === []) {
            return $content;
        }

        // Longest first, so an alternation never stops at a shorter prefix name.
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternation = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names));

        $content = (string) preg_replace('/`(' . $alternation . ')`/', '`$1' . self::SUFFIX . '`', $content);
        $content = (string) preg_replace(
            '/(^|[\s`("\'])\/(' . $alternation . ')(?![A-Za-z0-9_\/-])/m',
            '$1/$2' . self::SUFFIX,
            $content,
        );

        return (string) preg_replace('/\.\.\/(' . $alternation . ')\//', '../$1' . self::SUFFIX . '/', $content);
    }

    /**
     * Suffix each token that is exactly a published skill name. A `subagent:`
     * token never equals a skill name, so it passes through.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $names
     * @return list<string>
     */
    private function renameRequiredNames(array $tokens, array $names): array
    {
        $published = array_flip($names);

        return array_map(
            static fn (string $token): string => isset($published[$token]) ? $token . self::SUFFIX : $token,
            $tokens,
        );
    }

    /**
     * @param  list<string>  $names
     */
    private function renameOne(Skill $skill, array $names): Skill
    {
        $newName = $skill->name . self::SUFFIX;

        $frontmatter = $skill->frontmatter;
        $frontmatter['name'] = $newName;

        $metadata = $frontmatter['metadata'] ?? null;
        if (is_array($metadata) && is_string($metadata['boost-requires'] ?? null)) {
            $tokens = preg_split('/\s+/', trim($metadata['boost-requires']), -1, PREG_SPLIT_NO_EMPTY);
            $metadata['boost-requires'] = implode(' ', $this->renameRequiredNames($tokens === false ? [] : $tokens, $names));
            $frontmatter['metadata'] = $metadata;
        }

        $assets = [];
        foreach ($skill->assets as $asset) {
            $assets[] = str_ends_with($asset->relativePath, '.md')
                ? new SkillAsset($asset->relativePath, self::rewriteReferences($asset->contents, $names))
                : $asset;
        }

        return new Skill(
            name: $newName,
            description: $skill->description,
            frontmatter: $frontmatter,
            body: self::rewriteReferences($skill->body, $names),
            sourcePath: $skill->sourcePath,
            sourceVendor: $skill->sourceVendor,
            tags: $skill->tags,
            tagsValid: $skill->tagsValid,
            assets: $assets,
            requires: $this->renameRequiredNames($skill->requires, $names),
            requiresValid: $skill->requiresValid,
            requiredSubagents: $skill->requiredSubagents,
        );
    }
}
