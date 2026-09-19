import os
import re

path = r'C:\Users\HP\.gemini\antigravity-ide\brain\2b7da38b-112d-4c2c-b3c4-c1476b6fdd55\scratch\evenement_orig.php'
with open(path, encoding='utf-8', errors='ignore') as f:
    content = f.read()

print(f"Total length: {len(content)}")

# Search for hero action buttons
hero_buttons = re.findall(r'<button[^>]*>.*?</button>|<a[^>]*class="[^"]*btn[^"]*"[^>]*>.*?</a>', content, re.DOTALL | re.IGNORECASE)
print("=== HERO / ACTION BUTTONS ===")
for b in hero_buttons:
    if any(k in b.lower() for k in ['place', 'reserver', '3d', 'choisir', 'billet']):
        print(b.strip())
        print("-" * 40)

# Search for how ticket selection was structured
print("\n=== TICKET SELECTION & CHECKOUT FORM ===")
m = re.search(r'(<section class="event-tickets-section".*?</section>)', content, re.DOTALL)
if m:
    print(m.group(1)[:2000])
else:
    print("No event-tickets-section found")
