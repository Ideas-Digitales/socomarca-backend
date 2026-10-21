#!/usr/bin/env bash
# Renders compose.deploy.yml the same way the deploy workflow does and checks
# that Mailpit exists only on the qa profile.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

for cmd in docker envsubst python3; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "missing required command: $cmd" >&2
    exit 1
  fi
done

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

export REPO_NAME="ideas-digitales/socomarca-backend"
export APP_HOST="qa-api.example.test"
export MAILPIT_HOST="mailpit.example.test"
export MAILPIT_UI_AUTH='user@example.test:p&ss?}%*word'

render() {
  local tag="$1"
  local dest="$2"
  IMAGE_TAG="$tag" envsubst '${REPO_NAME} ${IMAGE_TAG} ${APP_HOST}' < compose.deploy.yml > "$dest"
  grep -q '\${MAILPIT_HOST:-}' "$dest"
  grep -q '\${MAILPIT_UI_AUTH:-}' "$dest"
  grep -q "firebase_credentials_${tag}" "$dest"
  grep -q "socomarca_logs_${tag}" "$dest"
  grep -q "Host(\`${APP_HOST}\`)" "$dest"
}

render qa "$workdir/qa.yml"
render prod "$workdir/prod.yml"
touch "$workdir/.env"

config_json() {
  local file="$1"
  shift
  docker compose --project-directory "$workdir" -f "$file" "$@" config --format json
}

config_json "$workdir/qa.yml" --profile qa > "$workdir/qa-profile.json"
config_json "$workdir/qa.yml" > "$workdir/qa-default.json"
config_json "$workdir/prod.yml" > "$workdir/prod-default.json"

python3 - "$workdir" << 'PY'
import json, pathlib, sys

workdir = pathlib.Path(sys.argv[1])
auth = "user@example.test:p&ss?}%*word"
host = "mailpit.example.test"

def load(name):
    return json.loads((workdir / name).read_text())

def services(doc):
    return doc.get("services", {})

qa = services(load("qa-profile.json"))
assert set(qa) == {"app", "worker", "mailpit"}, sorted(qa)
mailpit = qa["mailpit"]
assert mailpit["image"] == "axllent/mailpit:v1.31.6"
assert mailpit["container_name"] == "mailpit"

ports = mailpit.get("ports", [])
assert len(ports) == 1, ports
port = ports[0]
assert str(port.get("host_ip")) == "127.0.0.1"
assert int(port.get("target")) == 1025
assert str(port.get("published")) == "1025"
assert all(int(item.get("target", 0)) != 8025 for item in ports)

env = mailpit.get("environment", {})
if isinstance(env, list):
    env = dict(item.split("=", 1) for item in env)
assert env["MP_UI_AUTH"] == auth, env["MP_UI_AUTH"]
assert env["MP_ALLOWED_HOSTS"] == host
assert env["MP_SMTP_AUTH_ACCEPT_ANY"] == "1"
assert env["MP_SMTP_AUTH_ALLOW_INSECURE"] == "1"
assert env["MP_DATABASE"] == "/data/mailpit.db"
assert "MP_SMTP_AUTH" not in env

labels = mailpit.get("labels", {})
if isinstance(labels, list):
    labels = dict(item.split("=", 1) for item in labels)
assert labels["traefik.enable"] == "true"
assert labels["traefik.docker.network"] == "traefik-public"
assert labels["traefik.http.routers.mailpit.rule"] == f"Host(`{host}`)"
assert labels["traefik.http.routers.mailpit.entrypoints"] == "websecure"
assert labels["traefik.http.routers.mailpit.tls.certresolver"] == "myresolver"
assert labels["traefik.http.services.mailpit.loadbalancer.server.port"] == "8025"

networks = set(mailpit.get("networks", {}))
assert networks == {"traefik-public", "internal"}, networks
assert any("mailpit" in json.dumps(volume) for volume in mailpit.get("volumes", [])), mailpit.get("volumes")

for name in ("qa-default.json", "prod-default.json"):
    enabled = services(load(name))
    assert set(enabled) == {"app", "worker"}, (name, sorted(enabled))
    assert "mailpit" not in enabled

prod = services(load("prod-default.json"))
assert prod["app"]["container_name"] == "socomarca_app_prod"
assert prod["worker"]["container_name"] == "socomarca_worker_prod"
qa_app = services(load("qa-profile.json"))["app"]["container_name"]
assert qa_app == "socomarca_app_qa"

print("mailpit compose profile ok")
PY

# The QA validation step must accept a quoted password with shell metacharacters
# and reject an empty one. Run the script embedded in the workflow.
python3 - << 'PY'
import os, pathlib, re, subprocess, sys, tempfile, textwrap

workflow = pathlib.Path(".github/workflows/deploy.yml").read_text()
match = re.search(r"name: Validate Mailpit settings.*?python3 - << 'PY'\n(.*)\n {8}PY", workflow, re.S)
if not match:
    sys.exit("Validate Mailpit settings script was not found")
script = textwrap.dedent(match.group(1))

def run(env_text):
    with tempfile.TemporaryDirectory() as directory:
        pathlib.Path(directory, ".env").write_text(env_text)
        return subprocess.run(
            ["python3", "-c", script],
            cwd=directory,
            capture_output=True,
            text=True,
        )

ok = run(
    'MAILPIT_HOST="mailpit.example.test"\n'
    'MAILPIT_UI_AUTH="user@example.test:p&ss?}%*word"\n'
)
if ok.returncode != 0:
    sys.exit(ok.stderr or ok.stdout)
if "mailpit.example.test" not in ok.stdout:
    sys.exit("validator did not report the host")
if "p&ss" in ok.stdout:
    sys.exit("validator printed the UI password")

missing = run('MAILPIT_HOST=""\nMAILPIT_UI_AUTH=""\n')
if missing.returncode == 0:
    sys.exit("validator accepted empty Mailpit settings")

print("mailpit qa validation ok")
PY

echo "mailpit deploy checks passed"
