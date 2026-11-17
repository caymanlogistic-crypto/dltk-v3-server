FROM python:3.12-slim-bookworm
WORKDIR /opt/dltk
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY tools/workspace_realtime.py tools/workspace_realtime.py
USER 33:33
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
CMD ["python", "tools/workspace_realtime.py"]
