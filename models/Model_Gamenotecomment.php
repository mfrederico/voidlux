<?php
/**
 * Gamenotecomment FUSE model — a teammate's words under a producer's note: a reply, a refinement, a disagreement. They
 * go to the builder with the note. Relations: gamenote, member.
 */
class Model_Gamenotecomment extends \RedBeanPHP\SimpleModel {
    public function update() {
        $b = $this->bean;
        if ((int) $b->gamenoteId <= 0 || (int) $b->memberId <= 0) throw new \InvalidArgumentException('a comment needs a note and a member');
        if (trim((string) $b->text) === '') throw new \InvalidArgumentException('a comment needs words');
    }
}
