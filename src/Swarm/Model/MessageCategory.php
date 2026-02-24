<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

enum MessageCategory: string
{
    case Task = 'task';
    case Idea = 'idea';
    case Bounty = 'bounty';
    case Announcement = 'announcement';
    case Discussion = 'discussion';
    case Forum = 'forum';
    case Vote = 'vote';
    case Review = 'review';
    case DirectMessage = 'dm';
}
