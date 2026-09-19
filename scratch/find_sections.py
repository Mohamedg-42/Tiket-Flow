import re

with open(r'c:\wamp64\www\ticket-platform\client\evenement.php', encoding='utf-8', errors='ignore') as f:
    lines = f.readlines()

for idx, line in enumerate(lines, 1):
    if any(k in line for k in ['booking-layout-grid', 'event-hero-card', 'event-tickets-section', 'client3DSeatingModal']):
        print(f"Line {idx}: {line.strip()[:100]}")
