# Bongolava Jobs API (Drupal module)

REST API for the Bongolava Jobs Next.js frontend.

**Base URL:** `https://your-domain.com/bongolava_job`

## Install

1. Enable the module: `drush en bongolava_job -y`
2. Clear cache: `drush cr`
3. Ensure `sites/default/files` is writable (uploads go to `public://bongolava_job/`).

### Manual database install (admin UI)

If tables were not created on enable, open:

**Configuration → System → Bongolava Jobs**  
`/admin/config/system/bongolava-job`

- Review the **table status** list (Exists / Missing).
- Click **Install database tables** to run `bongolava_job.install` schema (creates only missing tables).
- Click **Run post-install tasks** to create the upload directory and grant API permissions.

Requires permission **Administer Bongolava Jobs**.

## Authentication

- Header: `Authorization: Bearer {token}`
- Token returned on login / register (see below)
- Format: `{uid}|{secret}`

```http
Content-Type: application/json
Accept: application/json
Authorization: Bearer 1|your-token-here
```

## Next.js proxy

```env
NEXT_PUBLIC_API_URL=https://your-domain.com
```

Frontend calls: `${NEXT_PUBLIC_API_URL}/bongolava_job/login`, etc.

Optional rewrite in `next.config.js`:

```js
async rewrites() {
  return [
    { source: '/bongolava_job/:path*', destination: `${process.env.NEXT_PUBLIC_API_URL}/bongolava_job/:path*` },
  ];
}
```

## Roles

Stored in `bongolava_job_user_meta.role`:

| Role | Access |
|------|--------|
| `candidate` | Profile, applications, saved jobs |
| `recruiter` | Jobs CRUD (own), recruiter profile |
| `admin` | Moderation, users, events, contacts |

Drupal user **uid 1** or role **`administrator`** is treated as admin.

```sql
UPDATE bongolava_job_user_meta SET role = 'admin' WHERE uid = YOUR_UID;
```

## Configuration

`config/install/bongolava_job.settings.yml`:

| Key | Default |
|-----|---------|
| `api.base_path` | `/bongolava_job` |
| `token_ttl_days` | 30 |
| `jobs_per_page` | 15 |
| `candidates_per_page` | 15 |

## API routes

All paths are relative to the site root (prefix `/bongolava_job`).

### Auth

| Method | Path | Auth |
|--------|------|------|
| POST | `/bongolava_job/login` | — |
| POST | `/bongolava_job/logout` | Bearer |
| GET | `/bongolava_job/me` | Bearer |
| POST | `/bongolava_job/register/candidate` | — |
| POST | `/bongolava_job/register/recruiter` | — |

### Jobs (public & recruiter)

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/jobs` | — |
| GET | `/bongolava_job/jobs/{id}` | — |
| POST | `/bongolava_job/jobs` | recruiter |
| PUT | `/bongolava_job/jobs/{id}` | recruiter |
| DELETE | `/bongolava_job/jobs/{id}` | recruiter |
| GET | `/bongolava_job/my-jobs` | recruiter |

### Jobs (admin)

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/admin/jobs/pending` | admin |
| POST | `/bongolava_job/admin/jobs/{id}/approve` | admin |
| POST | `/bongolava_job/admin/jobs/{id}/reject` | admin |
| POST | `/bongolava_job/admin/jobs/force-expire` | admin |

### Candidates

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/candidates` | — |
| GET | `/bongolava_job/candidates/{id}` | — |
| GET | `/bongolava_job/my-candidate-profile` | candidate |
| PUT | `/bongolava_job/my-candidate-profile` | candidate |
| POST | `/bongolava_job/candidate/upload-photo` | candidate |
| POST | `/bongolava_job/candidate/upload-cv` | candidate |
| GET | `/bongolava_job/candidate/download-cv` | candidate |
| DELETE | `/bongolava_job/candidate/delete-cv` | candidate |

### Recruiters

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/my-recruiter-profile` | recruiter |
| PUT | `/bongolava_job/my-recruiter-profile` | recruiter |
| POST | `/bongolava_job/recruiter/upload-logo` | recruiter |

### Applications

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/candidate/applications` | candidate |
| GET | `/bongolava_job/recruiter/applications` | recruiter |
| PUT | `/bongolava_job/recruiter/applications/{id}` | recruiter |
| POST | `/bongolava_job/jobs/{job_id}/apply` | candidate |

### Saved jobs

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/candidate/saved-jobs` | candidate |
| POST | `/bongolava_job/candidate/saved-jobs/{jobId}` | candidate |
| DELETE | `/bongolava_job/candidate/saved-jobs/{savedJobId}` | candidate |

### Events

| Method | Path | Auth |
|--------|------|------|
| GET | `/bongolava_job/events` | — |
| GET | `/bongolava_job/events/{id}` | — |
| POST | `/bongolava_job/events/{id}/register` | — |
| POST | `/bongolava_job/events` | admin |
| PUT | `/bongolava_job/events/{id}` | admin |
| DELETE | `/bongolava_job/events/{id}` | admin |

### Contact & admin

| Method | Path | Auth |
|--------|------|------|
| POST | `/bongolava_job/contact` | — |
| GET | `/bongolava_job/contacts` | admin |
| PUT | `/bongolava_job/admin/messages/{id}/read` | admin |
| DELETE | `/bongolava_job/admin/messages/{id}` | admin |
| GET | `/bongolava_job/admin/users` | admin |
| PUT | `/bongolava_job/admin/users/{id}/status` | admin |

## Error format

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

## Request examples (all endpoints)

Use these environment variables before running the examples:

```bash
BASE_URL="https://your-domain.com"
API="${BASE_URL}/bongolava_job"
TOKEN="1|your-token-here"
```

### Auth

```bash
curl -X POST "$API/login" -H "Content-Type: application/json" -d '{"email":"jean@example.mg","password":"password123"}'
curl -X POST "$API/logout" -H "Authorization: Bearer $TOKEN"
curl -X GET "$API/me" -H "Authorization: Bearer $TOKEN"
curl -X POST "$API/register/candidate" -H "Content-Type: application/json" -d '{"email":"new.candidate@example.mg","password":"password123","first_name":"Jean","last_name":"Rakoto","age":28,"location":"Antananarivo","phone":"+261340000000","job_target":"Developpeur","skills":"Vue, Laravel","experiences":"2 years","educations":"Licence"}'
curl -X POST "$API/register/recruiter" -H "Content-Type: application/json" -d '{"email":"new.recruiter@example.mg","password":"password123","organization":"Tech Bongolava","sector":"IT","phone":"+261320000000","address":"Antananarivo","website":"https://company.mg"}'
```

### Jobs (public & recruiter)

```bash
curl -X GET "$API/jobs?keyword=dev&location=Antananarivo&contract_type=CDI&is_remote=1"
curl -X GET "$API/jobs/12"
curl -X POST "$API/jobs" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"title":"Developpeur Full Stack","description":"...","location":"Tsiroanomandidy","contract_type":"CDI","sector":"Technologie","profession":"Developpeur","positions_count":2,"deadline":"2026-06-30","is_remote":true,"is_urgent":false,"contact_email":"hr@company.mg"}'
curl -X PUT "$API/jobs/12" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"salary":"1500000 - 2500000 Ar","is_urgent":true}'
curl -X DELETE "$API/jobs/12" -H "Authorization: Bearer $TOKEN"
curl -X GET "$API/my-jobs" -H "Authorization: Bearer $TOKEN"
```

### Jobs (admin)

```bash
curl -X GET "$API/admin/jobs/pending" -H "Authorization: Bearer $TOKEN"
curl -X POST "$API/admin/jobs/12/approve" -H "Authorization: Bearer $TOKEN"
curl -X POST "$API/admin/jobs/12/reject" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"rejection_reason":"Incomplete details"}'
curl -X POST "$API/admin/jobs/force-expire" -H "Authorization: Bearer $TOKEN"
```

### Candidates

```bash
curl -X GET "$API/candidates?keyword=vue&location=Antananarivo&experience_level=intermediaire"
curl -X GET "$API/candidates/1"
curl -X GET "$API/my-candidate-profile" -H "Authorization: Bearer $TOKEN"
curl -X PUT "$API/my-candidate-profile" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"job_target":"Backend Developer","skills":"PHP, Drupal, MySQL"}'
curl -X POST "$API/candidate/upload-photo" -H "Authorization: Bearer $TOKEN" -F "photo=@/path/to/photo.jpg"
curl -X POST "$API/candidate/upload-cv" -H "Authorization: Bearer $TOKEN" -F "cv=@/path/to/cv.pdf"
curl -X GET "$API/candidate/download-cv" -H "Authorization: Bearer $TOKEN" --output cv.pdf
curl -X DELETE "$API/candidate/delete-cv" -H "Authorization: Bearer $TOKEN"
```

### Recruiters

```bash
curl -X GET "$API/my-recruiter-profile" -H "Authorization: Bearer $TOKEN"
curl -X PUT "$API/my-recruiter-profile" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"organization":"Bongolava Tech","sector":"Technology","website":"https://bongolava.mg"}'
curl -X POST "$API/recruiter/upload-logo" -H "Authorization: Bearer $TOKEN" -F "logo=@/path/to/logo.png"
```

### Applications

```bash
curl -X GET "$API/candidate/applications" -H "Authorization: Bearer $TOKEN"
curl -X GET "$API/recruiter/applications" -H "Authorization: Bearer $TOKEN"
curl -X PUT "$API/recruiter/applications/5" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"status":"reviewed","rejection_reason":null}'
curl -X POST "$API/jobs/12/apply" -H "Authorization: Bearer $TOKEN" -F "name=Jean Rakoto" -F "email=jean@example.mg" -F "phone=+261340000000" -F "cover_letter=I am interested in this role." -F "cv=@/path/to/cv.pdf"
```

### Saved jobs

```bash
curl -X GET "$API/candidate/saved-jobs" -H "Authorization: Bearer $TOKEN"
curl -X POST "$API/candidate/saved-jobs/12" -H "Authorization: Bearer $TOKEN"
curl -X DELETE "$API/candidate/saved-jobs/7" -H "Authorization: Bearer $TOKEN"
```

### Events

```bash
curl -X GET "$API/events"
curl -X GET "$API/events/3"
curl -X POST "$API/events/3/register" -H "Content-Type: application/json" -d '{"name":"Jean Rakoto","email":"jean@example.mg","phone":"+261340000000"}'
curl -X POST "$API/events" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"title":"Salon Emploi","description":"Rencontre candidats/recruteurs","location":"Tsiroanomandidy","date":"2026-07-10","time":"09:00","type":"salon","organizer":"Bongolava Jobs","max_participants":200,"require_approval":false,"contact_email":"events@bongolava.mg","contact_phone":"+261340000001"}'
curl -X PUT "$API/events/3" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"location":"Antananarivo","time":"10:00"}'
curl -X DELETE "$API/events/3" -H "Authorization: Bearer $TOKEN"
```

### Contact & admin

```bash
curl -X POST "$API/contact" -H "Content-Type: application/json" -d '{"name":"Visitor","email":"visitor@example.mg","message":"Bonjour, je veux plus d infos."}'
curl -X GET "$API/contacts" -H "Authorization: Bearer $TOKEN"
curl -X PUT "$API/admin/messages/9/read" -H "Authorization: Bearer $TOKEN"
curl -X DELETE "$API/admin/messages/9" -H "Authorization: Bearer $TOKEN"
curl -X GET "$API/admin/users" -H "Authorization: Bearer $TOKEN"
curl -X PUT "$API/admin/users/4/status" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"status":"blocked"}'
```
