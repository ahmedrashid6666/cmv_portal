#!/usr/bin/env bash
# Tests, builds, pushes and deploys the exact CMV Accounts phase1-build commit.
set -euo pipefail

project_dir="$(cd "$(dirname "$0")" && pwd)"
portal_dir="$(cd "$project_dir/../portal" && pwd)"
env_file="$portal_dir/.deploy.env"
temporary_manifest=""

finish() {
    local exit_code=$?
    [[ -n "$temporary_manifest" ]] && rm -f "$temporary_manifest"
    if [[ "$exit_code" -eq 0 ]]; then
        echo '@@STATE|completed|Published and verified'
    else
        echo "@@STATE|failed|CMV Accounts deployment exited with code $exit_code" >&2
    fi
    exit "$exit_code"
}
trap finish EXIT

cd "$project_dir"
[[ -f "$env_file" ]] || { echo "Missing portal credentials: $env_file" >&2; exit 1; }
set -a; source "$env_file"; set +a

for key in HOSTINGER_SSH_HOST HOSTINGER_SSH_PORT HOSTINGER_SSH_USER HOSTINGER_SSH_PASSWORD CMV_ACCOUNTS_REMOTE_ROOT CMV_ACCOUNTS_URL; do
    [[ -n "${!key:-}" ]] || { echo "Missing $key in portal .deploy.env." >&2; exit 1; }
done
for command in git npm expect curl shasum; do
    command -v "$command" >/dev/null || { echo "Missing local command: $command" >&2; exit 1; }
done
[[ "$CMV_ACCOUNTS_REMOTE_ROOT" =~ ^~?/[A-Za-z0-9._/-]+$ ]] || { echo 'CMV_ACCOUNTS_REMOTE_ROOT contains unsupported characters.' >&2; exit 1; }
[[ "$CMV_ACCOUNTS_URL" =~ ^https://[A-Za-z0-9.-]+/?$ ]] || { echo 'CMV_ACCOUNTS_URL must be a fixed HTTPS origin.' >&2; exit 1; }

branch="$(git branch --show-current)"
[[ "$branch" == 'phase1-build' ]] || { echo "Expected phase1-build, found $branch." >&2; exit 1; }

deployable_changes() {
    git status --porcelain=v1 --untracked-files=normal \
        | grep -vE '^.. (\.deploy-dashboard-release\.json|vendor/pestphp/pest/\.temp/test-results)$' \
        || true
}

[[ -z "$(deployable_changes)" ]] || {
    echo 'Commit all deployable CMV Accounts changes before using the dashboard:' >&2
    deployable_changes >&2
    exit 1
}

echo '@@STATE|building_uploading|Testing and building CMV Accounts'
printf '@@TIMER|build_started|%s\n' "$(date +%s)"
./vendor/bin/pest
npm test
npm run build
printf '@@TIMER|build_finished|%s\n' "$(date +%s)"

[[ -s public/build/manifest.json ]] || { echo 'Vite build manifest is missing.' >&2; exit 1; }
[[ -z "$(deployable_changes)" ]] || {
    echo 'The production build changed tracked files. Review and commit them before deploying:' >&2
    deployable_changes >&2
    exit 1
}

revision="$(git rev-parse HEAD)"
echo "Pushing phase1-build revision $revision..."
git push origin phase1-build
remote_revision="$(git ls-remote origin refs/heads/phase1-build | awk '{print $1}')"
[[ "$remote_revision" == "$revision" ]] || { echo 'GitHub did not report the expected revision after push.' >&2; exit 1; }

echo '@@STATE|promoting|Deploying the exact revision on Hostinger'
printf '@@TIMER|promotion_started|%s\n' "$(date +%s)"
export CMV_DEPLOY_REVISION="$revision"
export CMV_DEPLOY_ROOT="$CMV_ACCOUNTS_REMOTE_ROOT"
expect <<'EXPECT'
set timeout 1800
set command "cd $env(CMV_DEPLOY_ROOT) && EXPECTED_REVISION=$env(CMV_DEPLOY_REVISION) ./deploy.sh"
spawn ssh -tt -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -p $env(HOSTINGER_SSH_PORT) $env(HOSTINGER_SSH_USER)@$env(HOSTINGER_SSH_HOST)
expect {
  -re "(?i)password:" { send -- "$env(HOSTINGER_SSH_PASSWORD)\r"; exp_continue }
  -re {\][$#] $} {}
  timeout { puts stderr "ERROR: SSH login timed out"; exit 124 }
  eof { puts stderr "ERROR: SSH closed before a shell prompt"; exit 1 }
}
send -- "$command; rc=\$?; echo __CMV_DEPLOYMENT_EXIT:\$rc; exit \$rc\r"
expect {
  eof {}
  timeout { puts stderr "ERROR: CMV deployment timed out"; exit 124 }
}
catch wait result
exit [lindex $result 3]
EXPECT
printf '@@TIMER|promotion_finished|%s\n' "$(date +%s)"

echo '@@STATE|browser_verification|Checking the public CMV Accounts release'
temporary_manifest="$(mktemp)"
curl --fail --silent --show-error --location --max-time 30 \
    "${CMV_ACCOUNTS_URL%/}/build/manifest.json?dashboard=$(date +%s)" \
    --output "$temporary_manifest"
local_manifest_hash="$(shasum -a 256 public/build/manifest.json | awk '{print $1}')"
live_manifest_hash="$(shasum -a 256 "$temporary_manifest" | awk '{print $1}')"
[[ "$live_manifest_hash" == "$local_manifest_hash" ]] || { echo 'Live Vite manifest does not match the deployed build.' >&2; exit 1; }
curl --fail --silent --show-error --location --max-time 30 "${CMV_ACCOUNTS_URL%/}/login" >/dev/null

changes="$(git status --porcelain=v1 --untracked-files=normal | grep -vE '^.. (\.deploy-dashboard-release\.json|vendor/pestphp/pest/\.temp/test-results)$' || true)"
fingerprint="$(printf '%s\n%s' "$revision" "$changes" | shasum -a 256 | awk '{print $1}')"
release_id="${local_manifest_hash:0:16}"
record_temp='.deploy-dashboard-release.json.new'
printf '{"release_id":"%s","manifest_sha256":"%s","source_revision":"%s","source_fingerprint":"%s","created_at":"%s"}\n' \
    "$release_id" "$local_manifest_hash" "$revision" "$fingerprint" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$record_temp"
mv -f "$record_temp" .deploy-dashboard-release.json

echo "DEPLOYMENT_COMPLETE: $release_id -> ${CMV_ACCOUNTS_URL%/}"
