// The place's own tests, which the test account runs on every push (node samapravierka.ts): composer install, then
// phpunit. Where no PHP 8.2 or later with pdo_sqlite and mbstring is on the PATH (the build environment has none), a
// static PHP and Composer of pinned versions are fetched into the temporary folder, each held to its SHA-256.
import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, writeFileSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const PHP = { url: "https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-linux-x86_64.tar.gz",
              sha256: "1aeed5bc7967977ca5b1da7163acd91bf9ba3ac56037045d4e91ee2ff2712bb7" };
const COMPOSER = { url: "https://getcomposer.org/download/2.11.2/composer.phar",
                   sha256: "dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028" };
const DIR = join(tmpdir(), "ramonak-samapravierka");

const run = (cmd: string, args: string[], quiet = false) =>
  spawnSync(cmd, args, { stdio: quiet ? "pipe" : "inherit", encoding: "utf8" });

async function fetched(url: string, sha256: string, path: string): Promise<string> {
  if (existsSync(path) && createHash("sha256").update(readFileSync(path)).digest("hex") === sha256) return path;
  const bytes = Buffer.from(await (await fetch(url)).arrayBuffer());
  const got = createHash("sha256").update(bytes).digest("hex");
  if (got !== sha256) throw Error(`${url}: SHA-256 ${got}, expected ${sha256}`);
  writeFileSync(path, bytes);
  return path;
}

// a PHP on the PATH that has what the tests need, or none
function ownPhp(): string | null {
  const v = run("php", ["-r", 'echo PHP_VERSION_ID >= 80200 && extension_loaded("pdo_sqlite") && extension_loaded("mbstring") ? "ok" : "no";'], true);
  return v.status === 0 && v.stdout.trim() === "ok" ? "php" : null;
}

mkdirSync(DIR, { recursive: true });
let php = ownPhp();
if (!php) {
  const archive = await fetched(PHP.url, PHP.sha256, join(DIR, "php.tar.gz"));
  if (run("tar", ["-xzf", archive, "-C", DIR]).status !== 0) throw Error("php: the archive does not unpack");
  php = join(DIR, "php");
}
const composer = await fetched(COMPOSER.url, COMPOSER.sha256, join(DIR, "composer.phar"));
for (const [what, args] of [["composer install", [composer, "install", "--no-interaction", "--no-progress"]],
                            ["phpunit", ["vendor/bin/phpunit", "--colors=never"]]] as const) {
  const r = run(php, [...args]);
  if (r.status !== 0) { console.error(`${what} failed (exit ${r.status})`); process.exit(r.status ?? 1); }
}
