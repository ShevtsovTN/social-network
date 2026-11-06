# Вспомогательный образ для экспериментов с отказами: tc (iproute2) для задержки сети через netem.
# Запускается с --net container:<узел> и --cap-add NET_ADMIN, в приложение и в замеры не входит.
FROM alpine:3.20.3

RUN apk add --no-cache iproute2
