<?php
/**
 * Game FUSE model — a row in `game`: one game folder (games/<slug>/) and its owner, team and store card.
 * Relations: member (the owner), team (optional: everyone on it may open the game). The folder is the truth for
 * everything else (see app\GameStore).
 */
class Model_Game extends \RedBeanPHP\SimpleModel {
    public function update() {
        $b = $this->bean;
        if (!preg_match(\app\GameStore::SLUG, (string) $b->slug)) throw new \InvalidArgumentException('a game slug is lowercase letters, digits and dashes');
        if (trim((string) $b->title) === '') $b->title = (string) $b->slug;
    }
}
