<?php
/**
 * Verify: a member confirms their email (lib/EmailConfirm.php says why).
 *   /verify              where they stand, and Send me the link          (members)
 *   /verify/send         POST: a fresh link by mail                       (members)
 *   /verify/confirm?t=   the page the mailed link opens, with a button    (anyone with the link: it may open signed out)
 *   /verify/doconfirm    POST: the button                                 (anyone with the link)
 * nav: none, reached from the notice on the forge and in the Studio, the builder's refusal, and the mailed link.
 */
namespace app;

use \Flight as Flight;

class Verify extends BaseControls\Control {
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        $this->render('verify/index', ['title' => 'Confirm your email', 'email' => (string) $this->member->email, 'done' => EmailConfirm::confirmed($this->member),
            'sent' => (string) $this->member->confirmSentAt]);
    }
    public function send($params = []): void {
        if (!$this->requireLogin()) return; if (Flight::request()->method !== 'POST') { Flight::redirect('/verify'); return; } $this->validateCSRF();
        try { EmailConfirm::send($this->member); $this->flash('success', 'The link is on its way to ' . $this->member->email . '. It works for ' . EmailConfirm::DAYS . ' days.'); }
        catch (\InvalidArgumentException $e) { $this->flash('error', $e->getMessage()); }
        catch (\RuntimeException $e) { $this->logger->error('EmailConfirm send failed', ['member' => (int) $this->member->id, 'error' => $e->getMessage()]); $this->flash('error', $e->getMessage()); }
        Flight::redirect('/verify');
    }
    public function confirm($params = []): void {
        $t = (string) $this->getParam('t', ''); $m = EmailConfirm::holder($t);
        $this->render('verify/confirm', ['title' => 'Confirm your email', 'token' => $m ? $t : '', 'email' => $m ? (string) $m->email : '']);
    }
    public function doconfirm($params = []): void {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/verify'); return; } $this->validateCSRF();
        try { $m = EmailConfirm::confirm((string) $this->getParam('t', '')); $this->flash('success', 'Your email ' . $m->email . ' is confirmed. The builder and the artist can work for you now.'); Flight::redirect($this->member ? '/forge' : '/auth/login'); }
        catch (\InvalidArgumentException $e) { $this->flash('error', $e->getMessage()); Flight::redirect($this->member ? '/verify' : '/auth/login'); }
    }
}
