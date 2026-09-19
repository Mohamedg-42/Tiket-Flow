import os

orig_path = r'C:\Users\HP\.gemini\antigravity-ide\brain\2b7da38b-112d-4c2c-b3c4-c1476b6fdd55\scratch\evenement_orig.php'
curr_path = r'c:\wamp64\www\ticket-platform\client\evenement.php'

with open(orig_path, encoding='utf-8', errors='ignore') as f:
    orig = f.read()

with open(curr_path, encoding='utf-8', errors='ignore') as f:
    curr = f.read()

print(f"Orig size: {len(orig)}, Curr size: {len(curr)}")
# Check what CSS is in curr that was added
print("seating-booking.css in curr:", "seating-booking.css" in curr)
print("booking-layout-grid in curr:", "booking-layout-grid" in curr)
print("event-hero-card in orig:", "event-hero-card" in orig)
print("event-hero-card in curr:", "event-hero-card" in curr)
print("event-tickets-section in orig:", "event-tickets-section" in orig)
print("event-tickets-section in curr:", "event-tickets-section" in curr)
print("client3DSeatingModal in orig:", "client3DSeatingModal" in orig)
print("client3DSeatingModal in curr:", "client3DSeatingModal" in curr)
