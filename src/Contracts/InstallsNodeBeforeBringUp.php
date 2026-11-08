<?php

namespace Woda\Worktrees\Contracts;

/**
 * A strategy whose node install runs on the host while the app runs in
 * containers: the node stage runs before `bringUp`.
 *
 * On Linux, a host install that replaces the `node_modules` folder (pnpm
 * removes an empty one and makes it again) detaches a volume that a running
 * container mounts there; the container then sees the host's install. Before
 * the containers start, nothing is mounted there yet, and a copy-on-write copy
 * from the main checkout finds no mount point folder in its way.
 */
interface InstallsNodeBeforeBringUp {}
