FROM mcr.microsoft.com/playwright/python:v1.64.1-noble
WORKDIR /opt/vault
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY *.py ./
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
USER pwuser
CMD ["uvicorn", "server:app", "--host", "0.0.0.0", "--port", "8010", "--no-access-log", "--log-level", "critical"]
