<?php
/**
 * FormGuard: keeps form-filling bots off sign-up and password reset until Cloudflare Turnstile is connected (Connections → Security),
 * and beside it after. A bot that tested the forms on 2026-11-04 made an account (member 79) because Turnstile had no keys and so did nothing.
 *
 * The page adds two fields to the form as it loads (views/app/form-guard.php, through the layout's scripts slot):
 *   vl_site   a text box no person sees; a bot that fills every field fills it        → refused
 *   vl_stamp  when the page was opened, signed with the app's key                     → missing (posted without opening the page, or
 *                                                                                        without running its script), forged, or under
 *                                                                                        3 seconds old (no person fills a form that fast) → refused
 * lib/app.php checks a POST to /auth/doregister and /auth/doforgot before the runtime's Auth sees it.
 */
namespace app;

class FormGuard {
    public const TRAP = 'vl_site';
    public const STAMP = 'vl_stamp';
    public const MIN_SECONDS = 3;
    public const MAX_SECONDS = 6 * 3600;
    /** The forms it guards: the page that shows each, and where each posts. */
    public const FORMS = ['/auth/register' => '/auth/doregister', '/auth/forgot' => '/auth/doforgot'];

    private static function key(): string {
        $k = (string) (\Flight::get('security.app_key') ?? '');
        if ($k === '') throw new \RuntimeException('FormGuard needs [security] app_key in conf/config.ini to sign its stamps');
        return $k;
    }
    /** A stamp for a page opened now: "<time>.<signature>". */
    public static function stamp(): string { $t = (string) time(); return $t . '.' . substr(hash_hmac('sha256', 'formguard|' . $t, self::key()), 0, 32); }

    /** Why a post looks like a bot's, or '' when it looks like a person's. */
    public static function problem(array $post): string {
        if (trim((string) ($post[self::TRAP] ?? '')) !== '') return 'the hidden field was filled';
        $s = (string) ($post[self::STAMP] ?? ''); if (!preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $s, $m)) return 'no stamp: posted without opening the page';
        if (!hash_equals(substr(hash_hmac('sha256', 'formguard|' . $m[1], self::key()), 0, 32), $m[2])) return 'a forged stamp';
        $age = time() - (int) $m[1];
        if ($age < self::MIN_SECONDS) return "sent {$age}s after the page opened";
        if ($age > self::MAX_SECONDS) return 'the page was open more than 6 hours';
        return '';
    }
}
