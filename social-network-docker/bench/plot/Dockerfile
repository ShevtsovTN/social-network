# Одноразовый образ для графиков нагрузочных замеров (make bench-plot). В приложения не входит.
FROM python:3.13.7-slim

RUN pip install --no-cache-dir matplotlib==3.10.3

# Кэш шрифтов matplotlib: контейнер запускается не от root, домашнего каталога нет.
ENV MPLCONFIGDIR=/tmp/matplotlib

WORKDIR /plot
COPY plot.py /plot/plot.py

ENTRYPOINT ["python", "/plot/plot.py"]
