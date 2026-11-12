#!/bin/bash
# Nome storico: il deploy di dev e del collaudo e' scripts/deploy.sh.
exec bash "$(dirname "$0")/deploy.sh" "$@"
