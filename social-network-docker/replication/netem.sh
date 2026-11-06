#!/usr/bin/env bash
# Искусственная задержка сети (tc netem) для экспериментов с лагом репликации. Только dev, нужны NET_ADMIN
# и образ social-network-netem (собирается автоматически из social-network-docker/netem).
#
#   netem.sh add <сервис> <задержка> <ip>...   задержать исходящий трафик сервиса, адресованный указанным IP
#   netem.sh del <сервис>                      убрать задержку
#   netem.sh show <сервис>                     показать qdisc и фильтры
#
# Задержка вешается на primary и только на трафик в сторону реплик: поток WAL приходит на реплики с опозданием,
# а ответы primary приложениям и метрики идут без задержки. Устройство: prio-очередь, в которой весь трафик по
# умолчанию идёт в полосу 1 (без задержки), а u32-фильтр по адресу назначения отправляет трафик реплик в полосу 3 с netem.
# Qdisc живёт в сетевом пространстве контейнера и исчезает вместе с ним (в том числе при kill -9).
set -euo pipefail

action="${1:?add, del или show}"
service="${2:?укажите сервис compose}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
device="${NETEM_DEVICE:-eth0}"

container="$(cd "$root" && docker compose -f docker-compose.yaml ps -q "$service")"
[ -n "$container" ] || { echo "Контейнер ${service} не запущен" >&2; exit 1; }

docker image inspect social-network-netem >/dev/null 2>&1 \
    || docker build -q -t social-network-netem "${root}/social-network-docker/netem" >/dev/null

tc_in_container() {
    docker run --rm --net "container:${container}" --cap-add NET_ADMIN social-network-netem sh -c "$1"
}

case "$action" in
    add)
        delay="${3:?укажите задержку, например 300ms}"
        shift 3
        [ "$#" -gt 0 ] || { echo "Укажите хотя бы один IP назначения" >&2; exit 1; }
        script="tc qdisc del dev ${device} root 2>/dev/null || true
tc qdisc add dev ${device} root handle 1: prio bands 3 priomap 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0
tc qdisc add dev ${device} parent 1:3 handle 30: netem delay ${delay}"
        for ip in "$@"; do
            script+=$'\n'"tc filter add dev ${device} protocol ip parent 1:0 prio 1 u32 match ip dst ${ip}/32 flowid 1:3"
        done
        tc_in_container "$script"
        echo "netem: ${service} -> $*: задержка ${delay}"
        ;;
    del)
        tc_in_container "tc qdisc del dev ${device} root 2>/dev/null || true"
        echo "netem: задержка на ${service} убрана"
        ;;
    show)
        tc_in_container "tc qdisc show dev ${device}; tc filter show dev ${device}"
        ;;
    *)
        echo "Неизвестное действие: ${action}" >&2
        exit 1
        ;;
esac
