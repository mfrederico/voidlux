<?php
/**
 * Gamenote FUSE model — one note the producer made while pointing at something in a running game: which game, who, the
 * scene, the position, what the kit found there (label + fields, JSON), the words, a screenshot crop on disk
 * (games/<slug>/.studio/notes/<id>.jpg), and its state: open (waiting in the batch), sent (handed to a build), done.
 * Relations: game, member.
 */
class Model_Gamenote extends \RedBeanPHP\SimpleModel {
    public const STATES = ['open', 'sent', 'done'];
    public function update() {
        $b = $this->bean;
        if (!in_array((string) $b->state, self::STATES, true)) throw new \InvalidArgumentException('a note is open, sent or done');
        if (trim((string) $b->text) === '') throw new \InvalidArgumentException('a note needs words');
    }
    /** The line the builder reads for this note. */
    public function line(int $n): string {
        $b = $this->bean; $what = json_decode((string) $b->what, true);
        $where = $what && !empty($what['label']) ? $what['label'] . ' ' . json_encode($what['fields'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES) : 'the spot';
        $shot = (string) $b->shot !== '' ? " (screenshot crop: {$b->shot})" : '';
        if ((string) $b->scene === 'note') return "{$n}. [a general note, not tied to a spot on screen{$shot}] " . trim((string) $b->text);
        return "{$n}. [scene {$b->scene}, at ({$b->x}, {$b->y}), pointing at {$where}{$shot}] " . trim((string) $b->text);
    }
}
