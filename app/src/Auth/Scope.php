<?php

declare(strict_types=1);

namespace Resm\Auth;

/**
 * How far a capability reaches. This is the second axis of the permission
 * matrix in spec 2.2 — the columns say which role, this says over whom.
 */
enum Scope
{
    /** The acting user's own record only. Every authenticated user has these. */
    case Own;

    /**
     * A named team. An Officer holds these over teams they are assigned to and
     * no others; an Admin holds them everywhere. A caller must name the team —
     * see the note in Access.
     */
    case Team;

    /** Season-wide administration. Admin only. */
    case Everywhere;
}
