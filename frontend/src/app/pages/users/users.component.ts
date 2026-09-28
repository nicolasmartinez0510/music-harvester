import { Component, inject, OnInit } from '@angular/core';

import { ApiService } from '../../core/api.service';
import { AuthUser } from '../../core/models';
import { IconComponent } from '../../shared/icon.component';

@Component({
  selector: 'app-users',
  imports: [IconComponent],
  templateUrl: './users.component.html',
  styleUrl: './users.component.css',
})
export class UsersComponent implements OnInit {
  private readonly api = inject(ApiService);

  users: AuthUser[] = [];
  errorMessage: string | null = null;
  userPendingDelete: AuthUser | null = null;
  deleting = false;

  ngOnInit(): void {
    this.reload();
  }

  approve(user: AuthUser): void {
    this.api.approveServerStorage(user.id).subscribe({
      next: () => this.reload(),
      error: () => {
        this.errorMessage = 'No se pudo aprobar el almacenamiento.';
      },
    });
  }

  remove(user: AuthUser): void {
    this.userPendingDelete = user;
    this.errorMessage = null;
  }

  cancelDelete(): void {
    if (this.deleting) {
      return;
    }
    this.userPendingDelete = null;
  }

  confirmDelete(): void {
    const user = this.userPendingDelete;
    if (user === null || this.deleting) {
      return;
    }

    this.deleting = true;
    this.api.deleteUser(user.id).subscribe({
      next: () => {
        this.deleting = false;
        this.userPendingDelete = null;
        this.reload();
      },
      error: () => {
        this.deleting = false;
        this.userPendingDelete = null;
        this.errorMessage = 'No se pudo borrar el usuario.';
      },
    });
  }

  private reload(): void {
    this.api.listUsers().subscribe({
      next: (users) => {
        this.users = users;
        this.errorMessage = null;
      },
      error: () => {
        this.errorMessage = 'No se pudieron cargar los usuarios.';
      },
    });
  }
}
