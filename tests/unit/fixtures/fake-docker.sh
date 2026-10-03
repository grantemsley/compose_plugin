#!/bin/bash
# A scripted stand-in for the docker CLI, used by the git deploy tests.
#
# It answers from files next to itself (the tests write them):
#   config.json / config.stderr / config.exit   docker compose ... config --format json
#   networks/<name>, volumes/<name>             these exist (network/volume inspect succeeds);
#                                               a network file holds its driver, if any
#   containers/<name>.json                      docker container inspect <name>
#   running.ids, running.json                   docker ps -q, and docker container inspect <ids...>
# Every call is appended to calls.log, one line per call.
here="$(cd "$(dirname "$0")" && pwd)"
printf '%s\n' "$*" >> "$here/calls.log"

case "$1" in
  compose)
    for arg in "$@"; do
      if [ "$arg" = "config" ]; then
        [ -f "$here/config.stderr" ] && cat "$here/config.stderr" >&2
        [ -f "$here/config.json" ] && cat "$here/config.json"
        exit "$(cat "$here/config.exit" 2>/dev/null || echo 0)"
      fi
    done
    exit 0
    ;;
  network|volume)
    kind="$1s"
    name="${*: -1}"
    if [ -e "$here/$kind/$name" ]; then
      # A network file may hold its driver, printed for --format {{.Driver}}.
      cat "$here/$kind/$name"
      exit 0
    fi
    echo "Error: No such $1: $name" >&2
    exit 1
    ;;
  ps)
    cat "$here/running.ids" 2>/dev/null
    exit 0
    ;;
  container)
    # container inspect -- <name or ids...>
    shift 3
    if [ "$#" -eq 1 ] && [ -f "$here/containers/$1.json" ]; then
      cat "$here/containers/$1.json"
      exit 0
    fi
    if [ -f "$here/running.ids" ] && [ "$*" = "$(tr '\n' ' ' < "$here/running.ids" | sed 's/ $//')" ]; then
      cat "$here/running.json"
      exit 0
    fi
    echo "Error: No such container: $*" >&2
    exit 1
    ;;
esac
exit 0
