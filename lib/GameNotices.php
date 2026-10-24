<?php
/**
 * GameNotices — what the forge tells people through Communications: a run finished, a game was shared with a team.
 * Every notice is a thread related to the game (relatedTo('game', id)), owned by the game's owner, so replies and the
 * next notice about the same game land in one conversation. Through NotifyService it also goes out by email when the
 * app has mail configured; without mail it stays in-app, which is fine for a studio.
 */
namespace app;

use \app\Bean;
use \app\services\NotifyService;

class GameNotices {
    public static function finished(string $slug, string $mode, string $outcome, int $byMemberId): void {
        $g = GameStore::row($slug); if (!$g) return;
        $owner = Bean::load('member', (int) $g->memberId); if (!$owner->id) return;
        $meta = GameStore::meta($g); $soak = $meta['lastSoak'];
        $url = GameStore::baseUrl() . '/game/view/' . $slug;
        $html = '<p><b>' . htmlspecialchars($g->title) . '</b>: the ' . htmlspecialchars($mode) . ' run ' . htmlspecialchars($outcome) . '.</p>'
              . '<p>Tasks ' . $meta['tasks']['done'] . '/' . $meta['tasks']['total'] . ' · checkpoints ' . $meta['checkpoints']
              . ($soak ? ' · last soak ' . ($soak['ok'] ? 'ok' : 'failing') . ($soak['fps'] ? ' at ' . $soak['fps'] . ' fps' : '') : '') . '</p>'
              . '<p><a href="' . htmlspecialchars($url) . '">Open the game in the forge</a></p>';
        try {
            NotifyService::create()->to((string) $owner->email, (string) ($owner->username ?? ''))->subject('Forge: ' . $g->title . ' — ' . $mode . ' ' . (str_starts_with($outcome, 'failed') ? 'failed' : 'finished'))
                ->relatedTo('game', (int) $g->id)->owner((int) $owner->id)->send($html);
        } catch (\Throwable $e) { error_log('ERROR GameNotices::finished: ' . $e->getMessage()); }
    }
}
