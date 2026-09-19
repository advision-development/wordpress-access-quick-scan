#!/usr/bin/env bash
#
# Run every CLI harness. These do not need a WordPress install: the handful of
# WordPress functions the plugin touches are stubbed in wp-stubs.php.

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

STATUS=0

# test-report.php was written for 0.10.0 and never added to this list, so the assertions that
# keep the session verifier out of the export have been passing unread ever since. It is in
# it now.
for suite in test-accounts.php test-sessions.php test-app-passwords.php test-registration.php test-actions.php test-report.php test-fleet.php test-shared.php test-sort.php test-group.php test-timeline.php test-filter.php test-updater.php test-uninstall.php test-markup.php test-asking.php; do
	echo "=============================================================="
	echo "  ${suite}"
	echo "=============================================================="

	if ! php "${suite}"; then
		STATUS=1
	fi

	echo
done

if [[ "${STATUS}" -ne 0 ]]; then
	echo "SUITE FAILED" >&2
fi

exit "${STATUS}"
