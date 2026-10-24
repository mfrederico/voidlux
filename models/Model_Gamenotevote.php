<?php
/**
 * Gamenotevote FUSE model — one member's upvote on one producer's note. One per member per note (toggled off by
 * deleting it). Relations: gamenote, member.
 */
class Model_Gamenotevote extends \RedBeanPHP\SimpleModel {
    public function update() {
        $b = $this->bean;
        if ((int) $b->gamenoteId <= 0 || (int) $b->memberId <= 0) throw new \InvalidArgumentException('a vote needs a note and a member');
    }
}
