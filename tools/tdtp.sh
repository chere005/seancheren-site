#!/bin/sh
# tdtp — test, deploy, tag, push. The site lane with the whole test run in
# front of it. See tools/dtp.sh for the lane itself.
exec sh "$(dirname "$0")/dtp.sh" --full "$@"
