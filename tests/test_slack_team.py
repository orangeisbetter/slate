"""Slack workspace gate helper. Card #32.

The callback itself trades a code with Slack over HTTPS, so no HTTP test can drive it;
what the suite can pin is the pure part: resolving the workspace out of the OIDC
responses, in every shape Slack has used. Runs PHP in a subprocess against this repo's
own src/lib.php.
"""
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
require $argv[1];
$cases = [
    [['https://slack.com/team_id' => 'T123'], [], 'T123'],
    [['team_id' => 'T456'], [], 'T456'],
    [['team' => ['id' => 'T789']], [], 'T789'],
    [[], ['team' => ['id' => 'T000']], 'T000'],
    [['https://slack.com/team_id' => 'T1', 'team_id' => 'T2'], [], 'T1'],
    [[], [], ''],
    [['team_id' => '  '], [], ''],
];
foreach ($cases as $i => [$u, $t, $want]) {
    $got = slack_team_id($u, $t);
    if ($got !== $want) {
        echo "FAIL $i: got [$got] want [$want]\n";
        exit(1);
    }
}
echo "ALL OK\n";
"""


def test_slack_team_id_covers_every_response_shape():
    r = subprocess.run(["php", "-r", PHP, str(ROOT / "src" / "lib.php")],
                       capture_output=True, text=True, timeout=30)
    assert r.returncode == 0, r.stdout + r.stderr
    assert "ALL OK" in r.stdout


def test_callback_enforces_the_gate():
    src = (ROOT / "public" / "login" / "slack-callback.php").read_text()
    assert "SLACK_ALLOWED_TEAM_ID" in src
    assert "slack_team_id(" in src
