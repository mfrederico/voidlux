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
    /** @param array $outcome GameAgent::outcome(): {state, why, cost} */
    public static function finished(string $slug, string $mode, array $outcome, int $byMemberId): void {
        $g = GameStore::row($slug); if (!$g) return;
        $owner = Bean::load('member', (int) $g->memberId); if (!$owner->id) return;
        $what = ['pipeline' => 'build', 'chat' => 'builder run', 'review' => 'review', 'fix' => 'playtest and fix'][$mode] ?? $mode;
        $how = ['finished' => 'finished', 'attention' => 'needs a look', 'failed' => 'failed', 'stopped' => 'stopped'][$outcome['state']];
        $meta = GameStore::meta($g); $soak = $meta['lastSoak'];
        $url = GameStore::baseUrl() . '/game/view/' . $slug;
        $html = '<p><b>' . htmlspecialchars($g->title) . '</b>: the ' . htmlspecialchars($what) . ' ' . $how . ($outcome['why'] !== '' ? ': ' . htmlspecialchars($outcome['why']) : '') . '.'
              . (isset($outcome['cost']) ? ' It cost $' . number_format((float) $outcome['cost'], 2) . '.' : '') . '</p>'
              . '<p>Tasks ' . $meta['tasks']['done'] . '/' . $meta['tasks']['total'] . ' · checkpoints ' . $meta['checkpoints']
              . ($soak ? ' · last playtest ' . ($soak['ok'] ? 'ok' : 'failing') . ($soak['fps'] ? ' at ' . $soak['fps'] . ' fps' : '') : '') . '</p>'
              . '<p><a href="' . htmlspecialchars($url) . '">Open the game in the forge</a></p>';
        $to = [(int) $owner->id => $owner];
        if ($byMemberId > 0 && !isset($to[$byMemberId])) { $by = Bean::load('member', $byMemberId); if ($by->id) $to[$byMemberId] = $by; } // a teammate who started it wants to know too
        foreach ($to as $id => $m) {
            try {
                $subject = 'Forge: ' . $g->title . ' — ' . $what . ' ' . $how;
                $sent = NotifyService::create()->to((string) $m->email, (string) ($m->username ?? ''))->subject($subject)->relatedTo('game', (int) $g->id)->owner($id)->send($html);
                if (!empty($sent['thread'])) { $t = Bean::load('thread', (int) $sent['thread']); $t->subject = $subject; Bean::store($t); } // one thread per game: its title says how the latest run went, not the first
            } catch (\Throwable $e) { error_log('ERROR GameNotices::finished: ' . $e->getMessage()); }
        }
    }
}
