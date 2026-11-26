"""График решардинга под нагрузкой для отчёта ДЗ «Шардинг диалогов» (make bench-dialog-plot).

Запуск: python dialog.py <каталог отчёта>. Читает <каталог>/results/<прогон>/timeline.csv и events.csv,
пишет <каталог>/img/reshard.png: по столбцу на прогон, сверху задержка отправки по секундам, снизу число
подтверждённых отправок в секунду. Серые полосы — операции с кластером.
"""

import csv
import sys
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402

RUNS = [("reshard", "Прогон 1"), ("reshard-repeat", "Прогон 2")]
OPERATIONS = [
    ("rebalance_start", "rebalance_end", "перенос\n19 шардов"),
    ("isolate_start", "isolate_end", "изоляция\nдиалога"),
    ("move_start", "move_end", "перенос\nгорячего шарда"),
]
SERIES = [("max_ms", "максимум за секунду", "#2a78d6"), ("p95_ms", "p95", "#eb6834")]  # пара проверена на различимость
TEXT, MUTED, GRID, SURFACE, BAND = "#0b0b0b", "#52514e", "#e4e3df", "#fcfcfb", "#dddcd7"

plt.rcParams.update({
    "figure.facecolor": SURFACE,
    "axes.facecolor": SURFACE,
    "axes.edgecolor": GRID,
    "axes.labelcolor": MUTED,
    "axes.titlecolor": TEXT,
    "xtick.color": MUTED,
    "ytick.color": MUTED,
    "axes.grid": True,
    "grid.color": GRID,
    "grid.linewidth": 0.8,
    "axes.spines.top": False,
    "axes.spines.right": False,
    "font.size": 10,
})


def load(directory: Path):
    with open(directory / "events.csv", encoding="utf-8") as source:
        events = {name: int(moment) for moment, name in csv.reader(source)}
    origin = events["load_start"]
    with open(directory / "timeline.csv", encoding="utf-8") as source:
        rows = [row for row in csv.DictReader(source) if row["request"] == "send"]
    # Первая и последняя секунды неполные.
    rows = rows[1:-1]

    return rows, {name: (moment - origin) / 1000 for name, moment in events.items()}


report = Path(sys.argv[1])
figure, axes = plt.subplots(2, len(RUNS), figsize=(12, 6.8), sharex="col", sharey="row",
                            gridspec_kw={"height_ratios": [3, 2]})

for column, (run, title) in enumerate(RUNS):
    rows, events = load(report / "results" / run)
    seconds = [int(row["second"]) for row in rows]
    latency, throughput = axes[0][column], axes[1][column]

    for axis in (latency, throughput):
        for start, end, _ in OPERATIONS:
            axis.axvspan(events[start], events[end], color=BAND, linewidth=0, zorder=0)
        axis.set_axisbelow(True)

    for key, label, color in SERIES:
        latency.plot(seconds, [float(row[key]) for row in rows], color=color, linewidth=1.6, label=label)
    for start, end, label in OPERATIONS:
        latency.annotate(label, ((events[start] + events[end]) / 2, 1), xycoords=("data", "axes fraction"),
                         ha="center", va="top", color=MUTED, fontsize=8)
    latency.set_title(title, pad=10)

    throughput.plot(seconds, [int(row["count"]) - int(row["errors"]) for row in rows], color=MUTED, linewidth=1.4)
    throughput.set_xlabel("секунды от начала нагрузки")

axes[0][0].set_yscale("log")
axes[0][0].set_ylim(0.8, 6000)
axes[0][0].set_yticks([1, 10, 100, 1000], ["1", "10", "100", "1000"])
axes[0][0].set_ylabel("задержка отправки, мс (лог. шкала)")
axes[1][0].set_ylim(0, None)
axes[1][0].set_ylabel("подтверждено\nотправок в секунду")
handles, labels = axes[0][0].get_legend_handles_labels()
figure.legend(handles, labels, loc="upper right", ncols=2, frameon=False)
figure.suptitle("Решардинг под нагрузкой: 400 отправок и 200 чтений в секунду", x=0.01, ha="left", color=TEXT)
figure.tight_layout()
(report / "img").mkdir(exist_ok=True)
figure.savefig(report / "img" / "reshard.png", dpi=150)
