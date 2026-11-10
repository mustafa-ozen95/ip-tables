# IPT — IP Address Tables

**English** · [Türkçe](README.tr.md)

A panel that keeps an organisation's IP address plan in groups: who uses
which address, for what, and in which group it lives.

Sibling projects: EYS (access vault), NYS (network panel) and the Identity
Management Centre (identity provider). The interface and the platform
(permissions, API, central sign-in, updates, settings, audit log) are shared
with them.

## Groups

Addresses live in **groups**, and permissions are granted per group. Four
built-in groups come with every installation; any number can be added:

| Group | Kind | Accepts |
| --- | --- | --- |
| IPv4 | `ipv4` | Public IPv4 addresses and networks |
| IPv6 | `ipv6` | Public IPv6 addresses and networks |
| Yerel IPv4 | `local-ipv4` | Private / local IPv4 (10/8, 172.16/12, 192.168/16 …) |
| Yerel IPv6 | `local-ipv6` | Private / local IPv6 (fc00::/7, fe80::/10 …) |
| *(new group)* | `mixed` | Any address |

A group's kind decides which addresses it accepts, and the rule is enforced
on both paths: an address that does not match cannot be added, and a group's
kind cannot be changed while it holds addresses that would no longer match.
The ranges treated as "local" can be changed with `IP_LOCAL_RANGES`.

Groups can be split into subgroups (e.g. "Customer B → DMZ").

## Addresses

Enter a single address (`192.0.2.10`) or a network (`198.51.100.0/24`,
`2001:db8::/48`). Addresses are stored in canonical form
(`2001:0DB8::0001` → `2001:db8::1`), cannot be entered twice in the same
group, and sort **numerically** (`.9` comes before `.10`).

## Permissions

Roles are read **live**, never copied onto the user; per-user rows are only
exceptions. Order of decision:

1. The user's row for the group
2. The user's general row
3. The role's permission for the group
4. The role's general permission
5. Deny (closed by default)

Create, update and delete are useless without **view** on the same group.
Built-in roles match the identity centre's slugs: `admin`, `network` (full
access to every group), `teknik` (views every group; default) and
`muhasebe` (no access).

## API

`/api/v1/addresses` is read-only and takes a token from Settings → API
(`Authorization: Bearer …`). A token can never exceed its owner: an address
the owner cannot see does not exist for the token (404). Filters: `grup`
(uuid), `tur`, `q`. Documentation is in the panel under Settings → API.

## Audit log

Every row carries the digest of the previous one, produced with
**HMAC-SHA256**. Updates and deletes are blocked at model level; the only way
to delete is the `activity:prune` command, which asks for a reason and
records itself in the chain.

## Installation

With Docker: [`docker/README.md`](docker/README.md) (Turkish).

Development:

```bash
composer setup          # dependencies, .env, key, migrations, build
php artisan admin:add --name="Full Name" --email=name@example.test
```

The repository holds no real data: the seeder only creates the first
administrator, and tests and visual-test data use documentation ranges
(RFC 5737 / 3849).

## Commands

| Command | Purpose |
| --- | --- |
| `identity:sync` | Applies role and status changes from the centre to local accounts |
| `update:check` | Compares the running version with the latest image build on GitHub |
| `activity:prune` | Prunes the audit log; asks for a reason |
| `admin:add` | Creates the first administrator; the password is never typed on the command line |
| `access:prepare-visuals` | Builds a fresh sqlite database for visual tests |

## Tests

```bash
composer test                                   # full suite
php artisan access:prepare-visuals && npx playwright test
```

## Rules

Coding standards and the security audit live in `CLAUDE.md`, interface rules
in `DESIGN.md`. In short: every controller carries authorisation, sequential
primary keys never leave the app (uuid), filtering happens in the database,
and every fix is locked in by a test.
