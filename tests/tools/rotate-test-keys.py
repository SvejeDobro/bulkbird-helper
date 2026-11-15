#!/usr/bin/env python3
"""Zamjenjuje testne WooCommerce ključeve na testnoj stranici i upisuje nove u test.env.

    tests/tools/rotate-test-keys.py <new|old> K_READ,K_RW

Secret se nigdje ne ispisuje: izlaz WP-CLI skripte čita samo ovaj program, nove vrijednosti idu
ravno u ~/.config/bulkbird-helper/test.env (600). Ispisuje se samo opis ključa, prava i zadnja
4 znaka consumer keya.
"""
import json
import os
import re
import subprocess
import sys

TARGETS = {
    "new": ("NEW", [os.path.expanduser("~/bin/wp83"), "eval-file", "-"]),
    "old": ("OLD", ["ssh", "-o", "BatchMode=yes", "teststoreold", "BBH_ROTATE={names} $HOME/bin/wp81 eval-file -"]),
}
ENV_FILE = os.path.expanduser("~/.config/bulkbird-helper/test.env")
SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "rotate-test-keys.php")


def main():
    if len(sys.argv) != 3 or sys.argv[1] not in TARGETS or not re.fullmatch(r"K_[A-Z]+(,K_[A-Z]+)*", sys.argv[2]):
        sys.exit(__doc__)
    prefix, command = TARGETS[sys.argv[1]]
    names = sys.argv[2]
    command = [part.format(names=names) for part in command]
    env = dict(os.environ, BBH_ROTATE=names)

    lines = open(ENV_FILE, encoding="utf-8").read().split("\n")
    for name in names.split(","):
        for half in ("CK", "CS"):
            key = "BBH_%s_%s_%s=" % (prefix, name, half)
            if sum(1 for line in lines if line.startswith(key)) != 1:
                sys.exit("test.env nema točno jedan redak %s — ništa nije mijenjano." % key[:-1])

    with open(SCRIPT, "rb") as script:
        done = subprocess.run(command, stdin=script, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env, check=False)
    marker = done.stdout.decode("utf-8", "replace").rfind("BBHJSON")
    if done.returncode != 0 or marker < 0:
        errors = [line for line in done.stderr.decode("utf-8", "replace").splitlines() if line.startswith("GREŠKA")]
        sys.exit("Zamjena nije uspjela (izlaz %d). %s" % (done.returncode, " ".join(errors)))
    new = json.loads(done.stdout.decode("utf-8")[marker + 7:].splitlines()[0])

    for name, key in new.items():
        for half, value in (("CK", key["ck"]), ("CS", key["cs"])):
            start = "BBH_%s_%s_%s=" % (prefix, name, half)
            lines = [start + value if line.startswith(start) else line for line in lines]
    temporary = ENV_FILE + ".new"
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
        handle.write("\n".join(lines))
    os.replace(temporary, ENV_FILE)
    os.chmod(ENV_FILE, 0o600)

    for name, key in new.items():
        print("%s %s: opozvan ključ #%d, novi #%d, prava %s, consumer key završava na …%s" % (
            prefix, name, key["old_key_id"], key["new_key_id"], key["permissions"], key["ck"][-4:]))


if __name__ == "__main__":
    main()
